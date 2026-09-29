<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Audio\Ffmpeg;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Segments\SegmentRepository;
use Sonoquill\Segments\SegmentStatus;
use Sonoquill\Storage\EpisodeStorage;
use Sonoquill\Voice\TextToSpeech;

/**
 * Speech synthesis: one TTS call per open segment.
 *
 * The job works through the queue until a time budget is reached and then
 * reschedules itself. An interruption therefore loses at most the segment in
 * progress; everything already paid for is preserved via the hash.
 */
final class Synthesis
{
    /** Maximum time a single pass works before it re-queues itself. */
    private const TIME_BUDGET_SECONDS = 240;

    public static function run(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return;
        }

        // If the text was reset while synthesis was running, every further
        // call is paid-for voice output for a text that no longer exists.
        if (!self::stillApproved($episodeId)) {
            return;
        }

        try {
            $tts = TextToSpeech::fromSettings();
            EpisodeStorage::ensureEpisodeDir($episodeId);
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'synthese', __('Could not be started: ', 'sonoquill') . $e->getMessage());

            return;
        }

        $segments = SegmentRepository::forEpisode($episodeId);
        if ($segments === []) {
            EpisodeRepository::log($episodeId, 'synthese', __('There are no segments.', 'sonoquill'));

            return;
        }

        $byIndex = [];
        foreach ($segments as $segment) {
            $byIndex[(int) $segment['idx']] = $segment;
        }

        $started = time();
        $done = 0;
        $failed = 0;
        $charactersUsed = 0;

        foreach ($segments as $segment) {
            if (self::isFresh($segment)) {
                continue;
            }

            if (!self::stillApproved($episodeId)) {
                EpisodeRepository::log($episodeId, 'synthese', __('Paused: the text is no longer approved.', 'sonoquill'));

                return;
            }

            if (time() - $started > self::TIME_BUDGET_SECONDS) {
                EpisodeRepository::log($episodeId, 'synthese', sprintf(
                    /* translators: %d: number of segments synthesized in this pass */
                    __('Time budget reached after %d segments, continuing.', 'sonoquill'),
                    $done
                ));
                Scheduler::continueSynthesis($episodeId);

                return;
            }

            $index = (int) $segment['idx'];

            try {
                $result = $tts->synthesize(
                    (string) $segment['text'],
                    (int) $segment['seed'],
                    (string) ($byIndex[$index - 1]['text'] ?? ''),
                    (string) ($byIndex[$index + 1]['text'] ?? '')
                );

                $relative = EpisodeStorage::segmentRelativePath($episodeId, $index, $tts->fileExtension());
                EpisodeStorage::write($relative, $result->audio);

                $measured = \Sonoquill\Audio\AudioEngine::durationMs(EpisodeStorage::absolutePath($relative));

                SegmentRepository::update((int) $segment['id'], [
                    'audio_path'     => $relative,
                    'source'         => 'tts',
                    'duration_ms'    => $measured ?? $result->alignmentDurationMs(),
                    'alignment_json' => (string) wp_json_encode($result->alignment),
                    'status'         => SegmentStatus::GENERATED,
                ]);

                $charactersUsed += $result->characterCount;
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                EpisodeRepository::log($episodeId, 'synthese', sprintf(
                    /* translators: 1: segment index, 2: error message */
                    __('Segment %1$d failed: %2$s', 'sonoquill'),
                    $index,
                    $e->getMessage()
                ));

                // A single failure should not abort the run, but a series of
                // errors should — otherwise a configuration error burns
                // through the entire queue.
                if ($failed >= 3) {
                    EpisodeRepository::log($episodeId, 'synthese', __('Aborted after three errors.', 'sonoquill'));

                    return;
                }
            }
        }

        if ($charactersUsed > 0) {
            EpisodeRepository::update($episodeId, [
                'chars_billed' => (int) ($episode['chars_billed'] ?? 0) + $charactersUsed,
            ]);
        }

        $summary = SegmentRepository::summary($episodeId);

        EpisodeRepository::log($episodeId, 'synthese', sprintf(
            /* translators: 1: segments generated, 2: characters used, 3: segments ready, 4: total segments, 5: total duration */
            __('%1$d segments generated, %2$d characters used. Status: %3$d of %4$d ready, total duration %5$s.', 'sonoquill'),
            $done,
            $charactersUsed,
            $summary['erzeugt'] + $summary['beanstandet'],
            $summary['gesamt'],
            self::formatDuration($summary['dauer_ms'])
        ));

        if ($summary['offen'] === 0) {
            Scheduler::queueMontage($episodeId);
        }
    }

    /**
     * @param array<string,mixed> $segment
     */
    private static function isFresh(array $segment): bool
    {
        $path = (string) $segment['audio_path'];

        return $path !== ''
            && (string) $segment['status'] !== SegmentStatus::PENDING
            && EpisodeStorage::exists($path);
    }

    public static function formatDuration(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private static function stillApproved(int $episodeId): bool
    {
        $episode = EpisodeRepository::find($episodeId);

        return $episode !== null && !in_array((string) $episode['status'], EpisodeStatus::BEFORE_TEXT_APPROVAL, true);
    }
}
