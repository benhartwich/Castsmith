<?php
declare(strict_types=1);

namespace Sonoquill\Audio;

/**
 * Builds the WebVTT transcript from the character timestamps of the synthesis.
 *
 * The reason for this approach: the previous transcript
 * came from Auphonic's speech recognition and was error-prone accordingly — the
 * feed contains "Sternwarte Garberg" and a misspelled version of the host's
 * name. The timestamps, by contrast, yield exactly the text that was spoken,
 * without a single recognition error.
 */
final class Transcript
{
    /** From this length on, a subtitle is broken up, even without a sentence end. */
    private const MAX_CUE_CHARS = 180;

    /**
     * @param list<array{offset_ms:int,alignment:array<string,mixed>,text:string}> $segments
     */
    public static function webvtt(array $segments, string $title = ''): string
    {
        $lines = ['WEBVTT', ''];

        if ($title !== '') {
            $lines[] = 'NOTE';
            $lines[] = $title;
            $lines[] = '';
        }

        foreach (self::cues($segments) as $cue) {
            $lines[] = sprintf('%s --> %s', self::timecode($cue['start_ms']), self::timecode($cue['end_ms']));
            $lines[] = $cue['text'];
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{offset_ms:int,alignment:array<string,mixed>,text:string}> $segments
     *
     * @return list<array{start_ms:int,end_ms:int,text:string}>
     */
    public static function cues(array $segments): array
    {
        $cues = [];

        foreach ($segments as $segment) {
            $characters = (array) ($segment['alignment']['characters'] ?? []);
            $starts = (array) ($segment['alignment']['character_start_times_seconds'] ?? []);
            $ends = (array) ($segment['alignment']['character_end_times_seconds'] ?? []);
            $offset = (int) $segment['offset_ms'];

            if ($characters === [] || count($characters) !== count($starts)) {
                // Without usable timestamps, the whole paragraph stays a single block.
                $text = trim((string) $segment['text']);
                if ($text !== '') {
                    $cues[] = [
                        'start_ms' => $offset,
                        'end_ms'   => $offset + (int) ($segment['duration_ms'] ?? 0),
                        'text'     => $text,
                    ];
                }

                continue;
            }

            $buffer = '';
            $cueStart = null;

            foreach ($characters as $position => $character) {
                $character = (string) $character;

                if ($cueStart === null && trim($character) !== '') {
                    $cueStart = $offset + (int) round(((float) $starts[$position]) * 1000);
                }

                $buffer .= $character;

                // A period only ends the sentence if it is followed by whitespace.
                // Otherwise "example.org" would split the subtitle mid-word.
                $next = (string) ($characters[$position + 1] ?? '');
                $followedByBreak = $next === '' || trim($next) === '';

                $endsSentence = $followedByBreak && preg_match('/[.!?…]$/u', $character) === 1;
                $tooLong = mb_strlen($buffer) >= self::MAX_CUE_CHARS && $character === ' ';

                if (($endsSentence || $tooLong) && trim($buffer) !== '') {
                    $cues[] = [
                        'start_ms' => $cueStart ?? $offset,
                        'end_ms'   => $offset + (int) round(((float) ($ends[$position] ?? $starts[$position])) * 1000),
                        'text'     => trim($buffer),
                    ];
                    $buffer = '';
                    $cueStart = null;
                }
            }

            if (trim($buffer) !== '') {
                $lastIndex = count($characters) - 1;
                $cues[] = [
                    'start_ms' => $cueStart ?? $offset,
                    'end_ms'   => $offset + (int) round(((float) ($ends[$lastIndex] ?? 0)) * 1000),
                    'text'     => trim($buffer),
                ];
            }
        }

        return $cues;
    }

    public static function timecode(int $milliseconds): string
    {
        $milliseconds = max(0, $milliseconds);
        $hours = intdiv($milliseconds, 3600000);
        $minutes = intdiv($milliseconds % 3600000, 60000);
        $seconds = intdiv($milliseconds % 60000, 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $milliseconds % 1000);
    }

    /**
     * Moves every cue of a WebVTT file by the difference of the chapter it
     * starts in.
     *
     * @param list<int> $chapterStarts predicted chapter starts in ms, ascending
     * @param list<int> $deltas        correction per chapter in ms
     */
    public static function shiftByChapters(string $vtt, array $chapterStarts, array $deltas): string
    {
        return (string) preg_replace_callback(
            '/^(\d{2}:\d{2}:\d{2}\.\d{3}) --> (\d{2}:\d{2}:\d{2}\.\d{3})/m',
            static function (array $m) use ($chapterStarts, $deltas): string {
                $start = self::parse($m[1]);
                $delta = 0;
                foreach ($chapterStarts as $i => $chapterStart) {
                    if ($start >= $chapterStart) {
                        $delta = $deltas[$i] ?? 0;
                    }
                }

                return self::timecode($start + $delta) . ' --> ' . self::timecode(self::parse($m[2]) + $delta);
            },
            $vtt
        );
    }

    private static function parse(string $timecode): int
    {
        [$h, $m, $rest] = explode(':', $timecode);
        [$s, $ms] = explode('.', $rest);

        return (((int) $h * 60 + (int) $m) * 60 + (int) $s) * 1000 + (int) $ms;
    }
}
