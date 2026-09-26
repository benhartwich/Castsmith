<?php
declare(strict_types=1);

namespace PodcastForge\Pipeline;

use PodcastForge\Jobs\Scheduler as JobScheduler;

/**
 * Queues the pipeline steps as background jobs.
 */
final class Scheduler
{
    public const HOOK_REDIGAT   = 'aaspf_redigat';
    public const HOOK_METADATA  = 'aaspf_metadaten';
    public const HOOK_FACTCHECK  = 'aaspf_faktenpruefung';
    public const HOOK_DICTIONARY = 'aaspf_woerterbuch';
    public const HOOK_SYNTHESIS = 'aaspf_synthese';
    public const HOOK_MONTAGE   = 'aaspf_montage';
    public const HOOK_PRODUCTION = 'aaspf_produktion';
    public const HOOK_PUBLISH    = 'aaspf_veroeffentlichen';
    public const HOOK_DAILY      = 'aaspf_taeglich';

    public static function register(): void
    {
        add_action(self::HOOK_REDIGAT, [Redigat::class, 'run'], 10, 1);
        add_action(self::HOOK_METADATA, [Metadata::class, 'run'], 10, 1);
        add_action(self::HOOK_FACTCHECK, [FactCheck::class, 'run'], 10, 1);
        add_action(self::HOOK_DICTIONARY, [DictionaryUpdate::class, 'run'], 10, 1);
        add_action(self::HOOK_SYNTHESIS, [Synthesis::class, 'run'], 10, 1);
        add_action(self::HOOK_MONTAGE, [Montage::class, 'run'], 10, 1);
        add_action(self::HOOK_PRODUCTION, [Production::class, 'run'], 10, 1);
        add_action(self::HOOK_PUBLISH, [Publish::class, 'run'], 10, 1);

        add_action(self::HOOK_DAILY, [self::class, 'daily'], 10, 0);
        add_action('init', [self::class, 'ensureDaily'], 20);

        \PodcastForge\Ai\BatchGate::register();
    }

    /**
     * Once a day: record the state of the services for the overview, clean up
     * collected batch responses after one week — and give add-ons the
     * opportunity to run their own tasks (such as creating an episode
     * according to a calendar) via the `podcast_forge_daily` hook.
     */
    public static function daily(): void
    {
        \PodcastForge\Health\Registry::runAll();
        \PodcastForge\Ai\BatchGate::cleanup();

        do_action('podcast_forge_daily');
    }

    /**
     * Schedules the daily run if it is missing — at around 6 a.m. local time.
     * This is checked at most once a day so that not every page load
     * queries the queue.
     */
    public static function ensureDaily(): void
    {
        if (!function_exists('as_has_scheduled_action') || get_transient('aaspf_taeglich_ok')) {
            return;
        }

        if (!as_has_scheduled_action(self::HOOK_DAILY, [], \PodcastForge\Jobs\Scheduler::GROUP)) {
            $first = new \DateTimeImmutable('tomorrow 06:00', wp_timezone());
            as_schedule_recurring_action($first->getTimestamp(), DAY_IN_SECONDS, self::HOOK_DAILY, [], \PodcastForge\Jobs\Scheduler::GROUP);
        }

        set_transient('aaspf_taeglich_ok', 1, DAY_IN_SECONDS);
    }

    public static function queueFactCheck(int $episodeId): bool
    {
        return self::queue(self::HOOK_FACTCHECK, $episodeId);
    }

    public static function queueDictionary(int $episodeId): bool
    {
        return self::queue(self::HOOK_DICTIONARY, $episodeId);
    }

    public static function queueSynthesis(int $episodeId): bool
    {
        return self::queue(self::HOOK_SYNTHESIS, $episodeId);
    }

    /**
     * A job schedules its own continuation.
     *
     * queue() prevents double clicks by checking whether the job is already
     * scheduled — and Action Scheduler also counts the job that is currently
     * RUNNING. If the synthesis calls queueSynthesis() after its time budget
     * has run out, it sees itself and schedules nothing: this is how the
     * October 2026 episode got stuck after 19 of 51 segments. The September
     * episode fit into a single budget, which is why this went unnoticed
     * before. Only pending jobs count here.
     */
    public static function continueSynthesis(int $episodeId): bool
    {
        if (!function_exists('as_schedule_single_action') || !function_exists('as_get_scheduled_actions')) {
            return false;
        }

        $pending = as_get_scheduled_actions([
            'hook'     => self::HOOK_SYNTHESIS,
            'args'     => [$episodeId],
            'group'    => JobScheduler::GROUP,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
        ], 'ids');

        if ($pending !== []) {
            return true;
        }

        return (bool) as_schedule_single_action(time() + 5, self::HOOK_SYNTHESIS, [$episodeId], JobScheduler::GROUP);
    }

    public static function queueMontage(int $episodeId): bool
    {
        return self::queue(self::HOOK_MONTAGE, $episodeId);
    }

    public static function queueRedigat(int $episodeId): bool
    {
        return self::queue(self::HOOK_REDIGAT, $episodeId);
    }

    public static function queueMetadata(int $episodeId): bool
    {
        return self::queue(self::HOOK_METADATA, $episodeId);
    }

    public static function queueProduction(int $episodeId): bool
    {
        return self::queue(self::HOOK_PRODUCTION, $episodeId);
    }

    public static function queuePublish(int $episodeId): bool
    {
        return self::queue(self::HOOK_PUBLISH, $episodeId);
    }

    /**
     * Trigger a step via its hook — for returning from a batch that only
     * knows the hook.
     */
    public static function queueHook(string $hook, int $episodeId): bool
    {
        return self::queue($hook, $episodeId);
    }

    private static function queue(string $hook, int $episodeId): bool
    {
        if (!function_exists('as_schedule_single_action')) {
            return false;
        }

        // Avoid scheduling twice in case of a double click.
        if (function_exists('as_has_scheduled_action')
            && as_has_scheduled_action($hook, [$episodeId], JobScheduler::GROUP)) {
            return true;
        }

        return (bool) as_schedule_single_action(time() + 5, $hook, [$episodeId], JobScheduler::GROUP);
    }
}
