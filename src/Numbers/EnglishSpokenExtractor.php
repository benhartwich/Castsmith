<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * Typed values from English number words — the English counterpart of
 * SpokenExtractor.
 *
 * Forms the English prompt asks for, and which this class reads back:
 * times "nine thirteen p.m.", "nine oh four a.m.", "nine p.m.", "nine
 * o'clock", "midnight", "noon"; dates "October fourth", "the fourth of
 * October"; decimals "four point eight (seven)"; large numbers "twenty-seven
 * thousand", "two point five million"; years "two thousand twenty-six".
 *
 * "one" and "a" are ambiguous in English ("this one", "a comet") and only
 * count as numbers in a number context: followed by a scale word, "point",
 * "hundred" or a time marker.
 */
final class EnglishSpokenExtractor
{
    /**
     * @return list<NumberValue>
     */
    public static function extract(string $text): array
    {
        $tokens = EnglishNumberParser::tokenize($text);
        $months = NumberValue::monthLookup('en');
        $values = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $word = $tokens[$i];

            if (isset($months[$word])) {
                // "May" is also a verb; only count it next to a day or at a sentence-like position.
                if ($word === 'may' && !self::nearDay($tokens, $i)) {
                    continue;
                }
                $values[] = NumberValue::month($months[$word], $word);
                continue;
            }

            if ($word === 'midnight') {
                $values[] = NumberValue::time(0, 0, $word);
                continue;
            }
            if ($word === 'noon' || $word === 'midday') {
                $values[] = NumberValue::time(12, 0, $word);
                continue;
            }

            $negative = false;
            $start = $i;
            if ($word === 'minus' && EnglishNumberParser::isNumberWord($tokens[$i + 1] ?? '')) {
                $negative = true;
                $start = $i + 1;
            }

            $parsed = EnglishNumberParser::parseAt($tokens, $start);
            if ($parsed === null) {
                continue;
            }

            $end = $start + $parsed['consumed']; // first token after the number
            $value = $parsed['value'];
            $next = $tokens[$end] ?? '';

            if ($parsed['ordinal']) {
                $values[] = NumberValue::day((int) $value, implode(' ', array_slice($tokens, $start, $parsed['consumed'])));
                $i = $end - 1;
                continue;
            }

            // Times: "nine pm", "nine oclock", "nine thirteen pm", "nine oh four am".
            if ($value >= 0 && $value <= 24 && floor($value) === $value) {
                $time = self::readTime($tokens, $end, (int) $value);
                if ($time !== null) {
                    [$hour, $minute, $used] = $time;
                    $values[] = NumberValue::time($hour, $minute, implode(' ', array_slice($tokens, $start, $parsed['consumed'] + $used)));
                    $i = $end + $used - 1;
                    continue;
                }
            }

            // Decimals: "four point eight seven", "two point five million".
            if ($next === 'point') {
                $digits = '';
                $j = $end + 1;
                while ($j < $count && ($d = EnglishNumberParser::digit($tokens[$j])) !== null) {
                    $digits .= (string) $d;
                    $j++;
                }
                if ($digits !== '') {
                    $value = (float) ((int) $value . '.' . $digits);
                    if (isset(EnglishNumberParser::SCALES[$tokens[$j] ?? ''])) {
                        $value *= EnglishNumberParser::SCALES[$tokens[$j]];
                        $j++;
                    }
                    $values[] = NumberValue::number($negative ? -$value : $value, implode(' ', array_slice($tokens, $start, $j - $start)));
                    $i = $j - 1;
                    continue;
                }
            }

            // Ambiguous "one" on its own: only in a number context.
            $words = array_slice($tokens, $start, $parsed['consumed']);
            if ($words === ['one'] && !self::isNumberContext($next)) {
                continue;
            }

            $values[] = NumberValue::number($negative ? -$value : $value, implode(' ', $words));
            $i = $end - 1;
        }

        return $values;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{0:int,1:int,2:int}|null hour (24 h), minute, tokens used after the hour
     */
    private static function readTime(array $tokens, int $index, int $hour): ?array
    {
        $word = $tokens[$index] ?? '';

        if ($word === 'am' || $word === 'pm') {
            return $hour >= 1 && $hour <= 12 ? [self::to24($hour, $word), 0, 1] : null;
        }
        if ($word === 'oclock') {
            return $hour <= 24 ? [$hour % 24, 0, 1] : null;
        }

        // Minutes: "oh four" or a number below sixty, then am/pm.
        $used = 0;
        $minute = null;
        if ($word === 'oh' && ($d = EnglishNumberParser::digit($tokens[$index + 1] ?? '')) !== null) {
            $minute = $d;
            $used = 2;
        } else {
            $parsed = EnglishNumberParser::parseAt($tokens, $index);
            if ($parsed !== null && !$parsed['ordinal'] && $parsed['value'] < 60 && floor($parsed['value']) === $parsed['value']) {
                $minute = (int) $parsed['value'];
                $used = $parsed['consumed'];
            }
        }

        $marker = $tokens[$index + $used] ?? '';
        if ($minute !== null && ($marker === 'am' || $marker === 'pm') && $hour >= 1 && $hour <= 12) {
            return [self::to24($hour, $marker), $minute, $used + 1];
        }

        return null;
    }

    private static function to24(int $hour, string $marker): int
    {
        if ($marker === 'am') {
            return $hour === 12 ? 0 : $hour;
        }

        return $hour === 12 ? 12 : $hour + 12;
    }

    private static function isNumberContext(string $next): bool
    {
        return $next === 'point' || $next === 'hundred' || isset(EnglishNumberParser::SCALES[$next])
            || $next === 'am' || $next === 'pm' || $next === 'oclock' || $next === 'percent';
    }

    /**
     * @param list<string> $tokens
     */
    private static function nearDay(array $tokens, int $i): bool
    {
        foreach ([$i - 2, $i - 1, $i + 1] as $j) {
            $parsed = isset($tokens[$j]) ? EnglishNumberParser::parseAt($tokens, $j) : null;
            if ($parsed !== null && $parsed['ordinal']) {
                return true;
            }
            if (isset($tokens[$j]) && preg_match('/^\d{1,2}(st|nd|rd|th)?$/', $tokens[$j]) === 1) {
                return true;
            }
        }

        return false;
    }
}
