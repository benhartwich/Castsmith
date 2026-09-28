<?php
declare(strict_types=1);

namespace Castsmith\Audio;

/**
 * Stretches a character alignment to a new total duration.
 *
 * The Voice Changer does not return any timestamps. The text, however, is
 * the same and is spoken by the same person, so a linear stretch is a
 * usable approximation. Without it, a re-recorded segment would show up in
 * the transcript as a single block instead of as sentences — and the more
 * passages are re-recorded, the coarser the transcript would become.
 *
 * The approximation is marked as such: the result carries the flag
 * `gestreckt`, so that it remains clear later on which timings were measured
 * and which were calculated.
 */
final class AlignmentScaler
{
    /**
     * @param array<string,mixed> $alignment
     *
     * @return array<string,mixed>
     */
    public static function scale(array $alignment, int $oldMs, int $newMs): array
    {
        if ($alignment === [] || $oldMs <= 0 || $newMs <= 0 || $oldMs === $newMs) {
            return $alignment;
        }

        $factor = $newMs / $oldMs;

        foreach (['character_start_times_seconds', 'character_end_times_seconds'] as $key) {
            if (!isset($alignment[$key]) || !is_array($alignment[$key])) {
                continue;
            }

            $alignment[$key] = array_map(
                static fn ($value): float => round(((float) $value) * $factor, 4),
                array_values($alignment[$key])
            );
        }

        $alignment['gestreckt'] = true;

        return $alignment;
    }
}
