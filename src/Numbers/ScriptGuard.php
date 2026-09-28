<?php
declare(strict_types=1);

namespace Castsmith\Numbers;

/**
 * The second of the number and content checks, applied to the speech script.
 *
 * It does not look at the values but at the form of the speech script:
 *
 * 1. Is there a digit outside the break tags? The voice clone pronounces
 *    digits unreliably, and written out they belong in the transcript.
 * 2. Does "Uhr null" appear anywhere? The inserted "null" is explicitly
 *    forbidden in the system prompt: "zwanzig Uhr vier", not "zwanzig Uhr null vier".
 *    Hour zero is not affected by this — "null Uhr sechs" is correct and
 *    is not touched by this check.
 * 3. More than five break tags? According to the system prompt this destabilises
 *    the model. This is a note, not an abort.
 */
final class ScriptGuard
{
    public const MAX_BREAKS = 5;

    public const SEVERITY_BLOCK = 'block';
    public const SEVERITY_NOTE  = 'hinweis';

    /**
     * @return list<array{severity:string,message:string,samples:list<string>}>
     */
    public static function check(string $script): array
    {
        $problems = [];

        $digits = self::digitsOutsideBreaks($script);
        if ($digits !== []) {
            $problems[] = [
                'severity' => self::SEVERITY_BLOCK,
                'message'  => sprintf(
                    /* translators: %d: number of digit sequences found outside break tags */
                    __( 'The speech script contains %d digit sequences outside the break tags. Numbers must be written out.', 'castsmith' ),
                    count($digits)
                ),
                'samples' => array_slice($digits, 0, 10),
            ];
        }

        $uhrNull = self::uhrNullOccurrences($script);
        if ($uhrNull !== []) {
            $problems[] = [
                'severity' => self::SEVERITY_BLOCK,
                'message'  => sprintf(
                    /* translators: %d: number of inserted "Uhr null" occurrences */
                    __( 'The speech script contains an inserted "Uhr null" %d times. Correct would be, for example, "zwanzig Uhr vier".', 'castsmith' ),
                    count($uhrNull)
                ),
                'samples' => array_slice($uhrNull, 0, 10),
            ];
        }

        $breaks = self::breakCount($script);
        if ($breaks > self::MAX_BREAKS) {
            $problems[] = [
                'severity' => self::SEVERITY_NOTE,
                'message'  => sprintf(
                    /* translators: 1: number of break tags in the script, 2: maximum recommended number of break tags */
                    __( 'The speech script has %1$d break tags. The system prompt allows at most %2$d, because more destabilise the model.', 'castsmith' ),
                    $breaks,
                    self::MAX_BREAKS
                ),
                'samples' => [],
            ];
        }

        return $problems;
    }

    public static function isBlocking(string $script): bool
    {
        foreach (self::check($script) as $problem) {
            if ($problem['severity'] === self::SEVERITY_BLOCK) {
                return true;
            }
        }

        return false;
    }

    /**
     * Digit sequences with some surrounding context, so the location can be found again.
     *
     * @return list<string>
     */
    public static function digitsOutsideBreaks(string $script): array
    {
        $clean = NumberExtractor::stripBreaks($script);

        if (preg_match_all('/.{0,25}\d+.{0,25}/u', $clean, $matches) === 0) {
            return [];
        }

        return array_map(
            static fn (string $s): string => trim(preg_replace('/\s+/u', ' ', $s) ?? $s),
            $matches[0]
        );
    }

    /**
     * @return list<string>
     */
    public static function uhrNullOccurrences(string $script): array
    {
        if (preg_match_all('/.{0,25}\bUhr\s+null\b.{0,25}/iu', $script, $matches) === 0) {
            return [];
        }

        return array_map(
            static fn (string $s): string => trim(preg_replace('/\s+/u', ' ', $s) ?? $s),
            $matches[0]
        );
    }

    public static function breakCount(string $script): int
    {
        return preg_match_all('/<break\b[^>]*>/iu', $script);
    }
}
