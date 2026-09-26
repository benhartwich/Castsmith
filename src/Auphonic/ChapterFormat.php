<?php
declare(strict_types=1);

namespace PodcastForge\Auphonic;

/**
 * Converts the measured chapter times into the line format that Auphonic expects.
 *
 * Format per line: HH:MM:SS.mmm Title
 *
 * The times come from the assembly step and are measured, not estimated. That is
 * exactly why speech recognition is turned off in the Auphonic preset: the
 * ASR variant costs credits and is less accurate.
 */
final class ChapterFormat
{
    /**
     * @param list<array<string,mixed>> $chapters
     */
    public static function toText(array $chapters): string
    {
        $lines = [];

        foreach ($chapters as $chapter) {
            $title = trim((string) ($chapter['titel'] ?? ''));
            if ($title === '') {
                continue;
            }

            $lines[] = self::timecode((int) ($chapter['start_ms'] ?? 0)) . ' ' . $title;
        }

        return implode("\n", $lines);
    }

    public static function timecode(int $milliseconds): string
    {
        $milliseconds = max(0, $milliseconds);

        return sprintf(
            '%02d:%02d:%02d.%03d',
            intdiv($milliseconds, 3600000),
            intdiv($milliseconds % 3600000, 60000),
            intdiv($milliseconds % 60000, 1000),
            $milliseconds % 1000
        );
    }
}
