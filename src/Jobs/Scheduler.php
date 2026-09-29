<?php
declare(strict_types=1);

namespace Sonoquill\Jobs;

/**
 * Integration with Action Scheduler.
 *
 * Blocking HTTP is ruled out: an episode needs several minutes of TTS,
 * spread across twenty or more individual requests to ElevenLabs. That does
 * not belong in a page request — not because of the time limit, which the
 * pool sets to 900 seconds, but because an aborted run could otherwise not
 * resume, and segments that have already been paid for would be lost.
 * Each step of the pipeline therefore becomes its own idempotent job.
 *
 * For now there is only one of them: a test job that proves the chain runs
 * at all.
 */
final class Scheduler
{
    public const HOOK_PING = 'aaspf_ping';
    public const GROUP     = 'sonoquill';
    public const STATE_OPTION = 'aaspf_ping_state';

    public static function register(): void
    {
        add_action(self::HOOK_PING, [PingJob::class, 'run'], 10, 1);
    }

    public static function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action');
    }

    /**
     * Schedules the test job and records when it was scheduled and with which token.
     *
     * @return array{ok:bool,message:string}
     */
    public static function schedulePing(): array
    {
        if (!self::isAvailable()) {
            return ['ok' => false, 'message' => __('Action Scheduler is not loaded.', 'sonoquill')];
        }

        $token = wp_generate_password(12, false);

        $actionId = as_schedule_single_action(
            time() + 5,
            self::HOOK_PING,
            [$token],
            self::GROUP
        );

        if (!$actionId) {
            return ['ok' => false, 'message' => __('The job was not accepted by Action Scheduler.', 'sonoquill')];
        }

        update_option(self::STATE_OPTION, [
            'token'         => $token,
            'action_id'     => (int) $actionId,
            'requested_at'  => time(),
            'ran_at'        => null,
            'ran_token'     => null,
        ], false);

        return [
            'ok'      => true,
            /* translators: %d: Action Scheduler action ID of the scheduled test job */
            'message' => sprintf(__('Test job scheduled as action #%d.', 'sonoquill'), (int) $actionId),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function state(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    /**
     * Which version of Action Scheduler is actually running — on this
     * installation several plugins ship their own copy, and the version
     * registry decides which one of them wins.
     */
    public static function activeVersion(): ?string
    {
        if (!class_exists('ActionScheduler_Versions')) {
            return null;
        }

        $version = \ActionScheduler_Versions::instance()->latest_version();

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Clears all still-pending jobs of this group on deactivation.
     */
    public static function unscheduleAll(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('', [], self::GROUP);
        }
    }
}
