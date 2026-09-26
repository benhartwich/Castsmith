<?php
declare(strict_types=1);

namespace PodcastForge\Voice;

/**
 * Result of a TTS call, including character timestamps.
 *
 * The timestamps are the reason for using the "with-timestamps" endpoint: they
 * are used to build chapter markers and the WebVTT transcript without a single
 * ASR error.
 */
final class TtsResult
{
    /**
     * @param array{characters:list<string>,character_start_times_seconds:list<float>,character_end_times_seconds:list<float>} $alignment
     */
    public function __construct(
        public readonly string $audio,
        public readonly array $alignment,
        public readonly int $characterCount
    ) {
    }

    /**
     * Duration according to the timestamps, in milliseconds.
     *
     * Only a first approximation — the actual measurement is done later with
     * ffprobe, because the audio file may still have a short fade-out at the end.
     */
    public function alignmentDurationMs(): int
    {
        $ends = $this->alignment['character_end_times_seconds'] ?? [];
        if ($ends === []) {
            return 0;
        }

        return (int) round(((float) end($ends)) * 1000);
    }
}
