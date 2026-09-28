<?php
declare(strict_types=1);

namespace Castsmith\Numbers;

/**
 * Reads English number words back into numbers.
 *
 * Unlike German, English writes numbers as word sequences ("twenty-one
 * thousand three hundred and five"), so the parser works on a token list and
 * reports how many tokens it consumed. Hyphens split into separate tokens
 * beforehand. The last word may be an ordinal ("twenty-first", "hundredth").
 */
final class EnglishNumberParser
{
    private const UNITS = [
        'zero' => 0, 'oh' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11,
        'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16,
        'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
    ];

    private const TENS = [
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
        'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    private const ORDINAL_UNITS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6,
        'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10, 'eleventh' => 11,
        'twelfth' => 12, 'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15,
        'sixteenth' => 16, 'seventeenth' => 17, 'eighteenth' => 18, 'nineteenth' => 19,
    ];

    private const ORDINAL_TENS = [
        'twentieth' => 20, 'thirtieth' => 30, 'fortieth' => 40, 'fiftieth' => 50,
        'sixtieth' => 60, 'seventieth' => 70, 'eightieth' => 80, 'ninetieth' => 90,
    ];

    public const SCALES = ['thousand' => 1000.0, 'million' => 1000000.0, 'billion' => 1000000000.0];

    private const ORDINAL_SCALES = ['hundredth' => 100.0, 'thousandth' => 1000.0, 'millionth' => 1000000.0];

    /**
     * Parses a number starting at $index.
     *
     * @param list<string> $tokens lower-case words
     *
     * @return array{value:float,consumed:int,ordinal:bool}|null
     */
    public static function parseAt(array $tokens, int $index): ?array
    {
        $count = count($tokens);
        $total = 0.0;      // finished scale groups
        $group = 0.0;      // current group below a thousand
        $seen = false;     // at least one number word
        $ordinal = false;
        $i = $index;
        $lastWasUnitOrTeen = false;

        while ($i < $count) {
            $word = $tokens[$i];

            // "a hundred", "a thousand" at the very start
            if (!$seen && ($word === 'a' || $word === 'an')) {
                $next = $tokens[$i + 1] ?? '';
                if ($next === 'hundred' || isset(self::SCALES[$next])) {
                    $group = 1.0;
                    $seen = true;
                    $i++;
                    continue;
                }

                break;
            }

            // "and" only between number words: "three hundred and five"
            if ($word === 'and' && $seen && self::isNumberWord($tokens[$i + 1] ?? '')) {
                $i++;
                continue;
            }

            if ($word !== 'oh' && isset(self::UNITS[$word])) {
                if ($lastWasUnitOrTeen && (int) $group % 100 !== 0) {
                    break; // "four five" is two numbers
                }
                $group += self::UNITS[$word];
                $seen = true;
                $lastWasUnitOrTeen = true;
                $i++;
                continue;
            }

            if (isset(self::TENS[$word])) {
                if ((int) $group % 100 !== 0) {
                    break; // "twenty thirty"
                }
                $group += self::TENS[$word];
                $seen = true;
                $lastWasUnitOrTeen = false;
                $i++;
                continue;
            }

            if ($word === 'hundred' && $seen) {
                $group = ($group === 0.0 ? 1.0 : $group) * 100;
                $lastWasUnitOrTeen = false;
                $i++;
                continue;
            }

            if (isset(self::SCALES[$word]) && $seen) {
                $total += ($group === 0.0 ? 1.0 : $group) * self::SCALES[$word];
                $group = 0.0;
                $lastWasUnitOrTeen = false;
                $i++;
                continue;
            }

            if (isset(self::ORDINAL_UNITS[$word]) && ((int) $group % 10 === 0 || !$seen)) {
                if ($lastWasUnitOrTeen) {
                    break;
                }
                $group += self::ORDINAL_UNITS[$word];
                $seen = true;
                $ordinal = true;
                $i++;
                break;
            }

            if (isset(self::ORDINAL_TENS[$word]) && (int) $group % 100 === 0) {
                $group += self::ORDINAL_TENS[$word];
                $seen = true;
                $ordinal = true;
                $i++;
                break;
            }

            if (isset(self::ORDINAL_SCALES[$word]) && $seen) {
                $total += ($group === 0.0 ? 1.0 : $group) * self::ORDINAL_SCALES[$word];
                $group = 0.0;
                $ordinal = true;
                $i++;
                break;
            }

            break;
        }

        if (!$seen) {
            return null;
        }

        return ['value' => $total + $group, 'consumed' => $i - $index, 'ordinal' => $ordinal];
    }

    public static function isNumberWord(string $word): bool
    {
        return isset(self::UNITS[$word]) || isset(self::TENS[$word]) || $word === 'hundred'
            || isset(self::SCALES[$word]) || isset(self::ORDINAL_UNITS[$word])
            || isset(self::ORDINAL_TENS[$word]) || isset(self::ORDINAL_SCALES[$word]);
    }

    /**
     * A single digit word for the part after "point" ("four point eight seven").
     */
    public static function digit(string $word): ?int
    {
        $value = self::UNITS[$word] ?? null;

        return $value !== null && $value < 10 ? $value : null;
    }

    /**
     * Lower-case tokens; hyphens and slashes separate words, periods of
     * "a.m."/"p.m." are kept together.
     *
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/\b([ap])\.\s?m\./u', '$1m', $text);
        $text = str_replace(['’', "'"], '', $text); // o'clock -> oclock
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values($parts);
    }
}
