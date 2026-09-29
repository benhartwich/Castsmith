<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * Converts German number words back into numbers.
 *
 * This is the reverse direction of the speech script: according to the system prompt,
 * the script contains not a single digit outside the break tags. Without this
 * back-conversion it would be impossible to check whether the model invented a number.
 *
 * Covered are cardinal numbers up into the billions and ordinal numbers
 * including the German declension endings ("dreiundzwanzigsten").
 *
 * This class does not know about WordPress and can be tested without WordPress.
 */
final class GermanNumberParser
{
    /** Number words below twenty, including the irregular forms. */
    private const UNITS = [
        'null'     => 0,
        'ein'      => 1,
        'eins'     => 1,
        'eine'     => 1,
        'zwei'     => 2,
        'zwo'      => 2,
        'drei'     => 3,
        'vier'     => 4,
        'fuenf'    => 5,
        'sechs'    => 6,
        'sieben'   => 7,
        'acht'     => 8,
        'neun'     => 9,
        'zehn'     => 10,
        'elf'      => 11,
        'zwoelf'   => 12,
        'dreizehn' => 13,
        'vierzehn' => 14,
        'fuenfzehn'=> 15,
        'sechzehn' => 16,
        'siebzehn' => 17,
        'achtzehn' => 18,
        'neunzehn' => 19,
    ];

    private const TENS = [
        'zwanzig'  => 20,
        'dreissig' => 30,
        'vierzig'  => 40,
        'fuenfzig' => 50,
        'sechzig'  => 60,
        'siebzig'  => 70,
        'achtzig'  => 80,
        'neunzig'  => 90,
    ];

    /** Ordinal stems that do not follow regularly from the cardinal number. */
    private const IRREGULAR_ORDINALS = [
        'erst'    => 1,
        'dritt'   => 3,
        'siebt'   => 7,
        'siebent' => 7,
        'acht'    => 8,
    ];

    /**
     * Cardinal number from a number word. null if it is not one.
     *
     * Spaces and hyphens are removed so that
     * "zwei Millionen dreihunderttausend" is also read as a single number.
     */
    public static function cardinal(string $words): ?float
    {
        $normalized = self::normalize($words);
        if ($normalized === '') {
            return null;
        }

        return self::parseScale($normalized);
    }

    /**
     * Ordinal number from a number word, with or without declension ending.
     *
     * "dritten" → 3, "dreiundzwanzigsten" → 23, "ersten" → 1.
     */
    public static function ordinal(string $words): ?int
    {
        $stem = self::normalize($words);
        if ($stem === '') {
            return null;
        }

        // Strip the declension ending: -e, -en, -em, -er, -es.
        $stem = preg_replace('/(en|em|er|es|e)$/u', '', $stem) ?? $stem;

        if (isset(self::IRREGULAR_ORDINALS[$stem])) {
            return self::IRREGULAR_ORDINALS[$stem];
        }

        // Compounds such as "einunddreissigst" or "dreiundzwanzigst".
        foreach (['st', 't'] as $suffix) {
            if (!str_ends_with($stem, $suffix)) {
                continue;
            }

            $base = substr($stem, 0, -strlen($suffix));
            if ($base === '') {
                continue;
            }

            // "achtundzwanzigst" ends in "t", the rest is a cardinal number.
            $value = self::parseScale($base);
            if ($value !== null && $value >= 0 && floor($value) === $value) {
                return (int) $value;
            }

            // Irregular final digit in a compound: "dreiunddreissigst"
            // is handled above, "einunddritt" does not exist — this is where
            // the special case "…undsiebt" or "…unddritt" applies.
            foreach (self::IRREGULAR_ORDINALS as $irregular => $number) {
                if (str_ends_with($base, $irregular)) {
                    $prefix = substr($base, 0, -strlen($irregular));
                    if ($prefix === '') {
                        return $number;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Cardinal or ordinal number, whichever fits.
     */
    public static function any(string $words): ?float
    {
        $cardinal = self::cardinal($words);
        if ($cardinal !== null) {
            return $cardinal;
        }

        $ordinal = self::ordinal($words);

        return $ordinal === null ? null : (float) $ordinal;
    }

    /**
     * Normalizes umlauts and removes separators.
     */
    public static function normalize(string $words): string
    {
        $text = mb_strtolower(trim($words), 'UTF-8');

        $text = strtr($text, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);

        // Spaces, non-breaking spaces and hyphens are dropped.
        return (string) preg_replace('/[\s\x{00A0}\-]+/u', '', $text);
    }

    /**
     * Splits by order of magnitude, descending.
     */
    private static function parseScale(string $text): ?float
    {
        if ($text === '') {
            return null;
        }

        foreach ([['milliard', 1000000000.0], ['million', 1000000.0]] as [$word, $factor]) {
            $position = strpos($text, $word);
            if ($position === false) {
                continue;
            }

            $left = substr($text, 0, $position);
            // "millionen" and "milliarden" carry a plural ending.
            $rest = substr($text, $position + strlen($word));
            $rest = preg_replace('/^(en|e|n)/', '', $rest) ?? $rest;

            $multiplier = $left === '' ? 1.0 : self::parseScale($left);
            if ($multiplier === null) {
                return null;
            }

            $remainder = $rest === '' ? 0.0 : self::parseScale($rest);
            if ($remainder === null) {
                return null;
            }

            return $multiplier * $factor + $remainder;
        }

        foreach ([['tausend', 1000.0], ['hundert', 100.0]] as [$word, $factor]) {
            $position = strpos($text, $word);
            if ($position === false) {
                continue;
            }

            $left = substr($text, 0, $position);
            $rest = substr($text, $position + strlen($word));

            $multiplier = $left === '' ? 1.0 : self::parseScale($left);
            if ($multiplier === null) {
                return null;
            }

            $remainder = $rest === '' ? 0.0 : self::parseScale($rest);
            if ($remainder === null) {
                return null;
            }

            return $multiplier * $factor + $remainder;
        }

        return self::parseBelowHundred($text);
    }

    /**
     * Numbers below one hundred, including the form "einundzwanzig".
     */
    private static function parseBelowHundred(string $text): ?float
    {
        if (isset(self::UNITS[$text])) {
            return (float) self::UNITS[$text];
        }

        if (isset(self::TENS[$text])) {
            return (float) self::TENS[$text];
        }

        // "vierundzwanzig": units, then "und", then tens.
        $position = strpos($text, 'und');
        if ($position !== false && $position > 0) {
            $ones = substr($text, 0, $position);
            $tens = substr($text, $position + 3);

            if (isset(self::UNITS[$ones], self::TENS[$tens])) {
                return (float) (self::UNITS[$ones] + self::TENS[$tens]);
            }
        }

        return null;
    }
}
