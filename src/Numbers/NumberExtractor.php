<?php
declare(strict_types=1);

namespace Sonoquill\Numbers;

/**
 * Reads every numeric value in a text, whether written as digits or as words.
 *
 * Both sides of the comparison go through this same class. That is the
 * crucial point: the fact script mixes both notations, and an asymmetric
 * comparison reported every spelled-out number in the source as something
 * the model had invented.
 */
final class NumberExtractor
{
    /**
     * @return list<NumberValue>
     */
    public static function extract(string $text, string $language = 'de'): array
    {
        $clean = self::stripBreaks($text);

        $digits = DigitExtractor::extract($clean, $language);
        $rest = DigitExtractor::stripped($clean, $language);
        $spoken = $language === 'en' ? EnglishSpokenExtractor::extract($rest) : SpokenExtractor::extract($rest);

        return array_merge($digits, $spoken);
    }

    /**
     * Removes break tags before any evaluation.
     *
     * `<break time="1.5s" />` contains digits that are not part of the spoken
     * text. Without this step the comparison reports them as invented
     * numbers — which is exactly why only digits outside the break tags
     * are counted.
     */
    public static function stripBreaks(string $text): string
    {
        return (string) preg_replace('/<break\b[^>]*>/iu', ' ', $text);
    }

    /**
     * Values counted by their key.
     *
     * @return array<string,int>
     */
    public static function counted(string $text, string $language = 'de'): array
    {
        $counts = [];
        foreach (self::extract($text, $language) as $value) {
            $counts[$value->key] = ($counts[$value->key] ?? 0) + 1;
        }

        return $counts;
    }
}
