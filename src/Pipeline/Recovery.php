<?php
declare(strict_types=1);

namespace Castsmith\Pipeline;

use Castsmith\Db\EpisodeRepository;
use Castsmith\Db\EpisodeStatus;
use Castsmith\Segments\SegmentRepository;
use Castsmith\Storage\EpisodeStorage;
use Castsmith\Support\RunLog;

/**
 * Resumption of aborted runs.
 *
 * Every step of the chain can be repeated on its own, and the segment hash
 * ensures that nothing already paid for is paid for again. What is missing
 * is the answer to "something is stuck here" — and that is exactly what this
 * class provides: it detects the standstill and knows which job is missing.
 */
final class Recovery
{
    /** After how long a running step counts as stuck. */
    private const STALE_REDIGAT_SECONDS    = 15 * MINUTE_IN_SECONDS;
    private const STALE_SYNTHESIS_SECONDS  = 20 * MINUTE_IN_SECONDS;
    private const STALE_PRODUCTION_SECONDS = 3 * HOUR_IN_SECONDS;

    /**
     * Why the episode is stuck. null means: it is not stuck.
     *
     * @param array<string,mixed> $episode
     */
    public static function stuckReason(array $episode): ?string
    {
        $status = (string) $episode['status'];
        $age = self::ageSeconds($episode);

        // An episode waiting for a batch is not stuck — it is waiting, for up to 24 hours.
        if (\Castsmith\Ai\BatchGate::pendingFor((int) $episode['id']) !== null) {
            return null;
        }

        if ($status === EpisodeStatus::SOURCE_RUNNING) {
            $source = \Castsmith\Source\Sources::forEpisode($episode);
            $limit = $source->staleAfterSeconds();
            if ($limit > 0 && $age > $limit) {
                /* translators: 1: source label, 2: human-readable time span */
                return sprintf(__('%1$s: no progress for %2$s.', 'castsmith'), $source->label(), human_time_diff(time() - $age));
            }
        }

        if ($status === EpisodeStatus::REDIGAT_RUNNING && $age > self::STALE_REDIGAT_SECONDS) {
            /* translators: %s: human-readable time span */
            return sprintf(__('The edit has been running for %s without progress.', 'castsmith'), human_time_diff(time() - $age));
        }

        // A step that last ended with an error counts as stuck immediately
        // — not only after a deadline has passed. The first episode looked
        // fine for three hours while the run log had long since said
        // "Download failed".
        // The log is already in the row — do not look it up again,
        // stuckEpisodes() goes through up to twenty-five episodes.
        $fehler = RunLog::lastFailure(EpisodeRepository::decodeList($episode['run_log'] ?? null));
        if ($fehler !== null) {
            return sprintf('%s: %s', ucfirst($fehler['schritt']), $fehler['text']);
        }

        if ($status === EpisodeStatus::PRODUCING && $age > self::STALE_PRODUCTION_SECONDS) {
            /* translators: %s: human-readable time span */
            return sprintf(__('Auphonic has not responded for %s.', 'castsmith'), human_time_diff(time() - $age));
        }

        if ($status === EpisodeStatus::TEXT_APPROVED) {
            $summary = SegmentRepository::summary((int) $episode['id']);

            if ($summary['gesamt'] > 0 && $summary['offen'] > 0 && $age > self::STALE_SYNTHESIS_SECONDS) {
                return sprintf(
                    /* translators: 1: open segments, 2: total segments, 3: human-readable time span */
                    __('%1$d of %2$d segments are still missing, and nothing has been added for %3$s.', 'castsmith'),
                    $summary['offen'],
                    $summary['gesamt'],
                    human_time_diff(time() - $age)
                );
            }

            $mix = (string) ($episode['mixed_audio_path'] ?? '');
            if ($summary['gesamt'] > 0 && $summary['offen'] === 0 && ($mix === '' || !EpisodeStorage::exists($mix))) {
                return __('All segments are finished, but there is no assembled version.', 'castsmith');
            }
        }

        return null;
    }

    /**
     * Re-queues the step that is missing.
     *
     * @return string What was done, for the run log.
     */
    public static function resume(int $episodeId): string
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return __('The episode does not exist.', 'castsmith');
        }

        $status = (string) $episode['status'];

        if ($status === EpisodeStatus::SOURCE_RUNNING || $status === EpisodeStatus::SOURCE_FAILED) {
            $message = \Castsmith\Source\Sources::forEpisode($episode)->resume($episodeId);

            return $message !== '' ? $message : __('This source could not be resumed; create the episode again.', 'castsmith');
        }

        if ($status === EpisodeStatus::REDIGAT_RUNNING || $status === EpisodeStatus::REDIGAT_FAILED) {
            Scheduler::queueRedigat($episodeId);

            return __('The edit has been rescheduled.', 'castsmith');
        }

        if ($status === EpisodeStatus::PRODUCING) {
            // Perhaps the production finished long ago and only the callback
            // got lost. Checking costs nothing.
            Scheduler::queuePublish($episodeId);

            return __('Checking with Auphonic whether the production is finished.', 'castsmith');
        }

        $summary = SegmentRepository::summary($episodeId);

        if ($summary['gesamt'] === 0) {
            return __('There are no segments yet — that is what the "Generate audio" button is for.', 'castsmith');
        }

        if ($summary['offen'] > 0) {
            Scheduler::queueSynthesis($episodeId);

            /* translators: %d: number of missing segments */
            return sprintf(__('The synthesis has been rescheduled, %d segments are still missing.', 'castsmith'), $summary['offen']);
        }

        Scheduler::queueMontage($episodeId);

        return __('The assembly has been rescheduled.', 'castsmith');
    }

    /**
     * All episodes that are currently stuck.
     *
     * @return list<array{id:int,titel:string,grund:string}>
     */
    public static function stuckEpisodes(int $limit = 25): array
    {
        $stuck = [];

        // Hidden episodes (trial and comparison runs) are not reported.
        $hidden = get_option('aaspf_ausgeblendet', []);
        $hidden = is_array($hidden) ? array_map('intval', $hidden) : [];

        foreach (EpisodeRepository::recent($limit) as $episode) {
            if (in_array((int) $episode['id'], $hidden, true)) {
                continue;
            }

            $reason = self::stuckReason($episode);
            if ($reason === null) {
                continue;
            }

            $title = (string) ($episode['episode_title'] ?? '');

            $stuck[] = [
                'id'    => (int) $episode['id'],
                /* translators: %d: episode ID */
                'titel' => $title !== '' ? $title : sprintf(__('Episode %d', 'castsmith'), (int) $episode['id']),
                'grund' => $reason,
            ];
        }

        return $stuck;
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function ageSeconds(array $episode): int
    {
        $updated = (string) ($episode['updated_at'] ?? '');
        if ($updated === '') {
            return 0;
        }

        $timestamp = strtotime($updated . ' ' . wp_timezone_string());

        return $timestamp === false ? 0 : max(0, time() - $timestamp);
    }
}
