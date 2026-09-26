<?php
declare(strict_types=1);

namespace PodcastForge\Podlove;

/**
 * Builds the identifier under which the episode is stored in Podlove.
 *
 * It determines the public file name — for previous episodes, for example
 * `AASPodcastJuli2026cutted.mp3`. That is why it is built from the month and
 * year in the title rather than from the whole title: the title is now
 * content-driven and changes completely every month.
 *
 * This class has no knowledge of WordPress and can be tested without WordPress.
 */
final class SlugBuilder
{
    private const MONTHS = [
        'jänner' => 'Jaenner', 'januar' => 'Januar', 'februar' => 'Februar', 'feber' => 'Feber',
        'märz' => 'Maerz', 'april' => 'April', 'mai' => 'Mai', 'juni' => 'Juni',
        'juli' => 'Juli', 'august' => 'August', 'september' => 'September',
        'oktober' => 'Oktober', 'november' => 'November', 'dezember' => 'Dezember',
        'january' => 'January', 'february' => 'February', 'march' => 'March', 'may' => 'May',
        'june' => 'June', 'july' => 'July', 'october' => 'October', 'december' => 'December',
    ];

    public static function fromTitle(string $title, string $prefix = 'Podcast'): string
    {
        $month = '';
        foreach (self::MONTHS as $needle => $canonical) {
            // Whole words only: otherwise "mai" would also match "Mailand" and "may" would match "Mayor".
            if (preg_match('/(?<![\p{L}])' . preg_quote($needle, '/') . '(?![\p{L}])/iu', $title) === 1) {
                $month = $canonical;
                break;
            }
        }

        $year = preg_match('/\b(20\d{2})\b/', $title, $m) === 1 ? $m[1] : '';

        if ($month !== '' && $year !== '') {
            return $prefix . $month . $year;
        }

        // No recognizable month: build a file-safe form from the title.
        $fallback = self::asciify($title);

        return $fallback === '' ? $prefix : $prefix . $fallback;
    }

    public static function asciify(string $text): string
    {
        $text = strtr($text, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
        ]);

        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_map(
            static fn (string $w): string => ucfirst(mb_strtolower($w, 'UTF-8')),
            array_slice($words, 0, 6)
        );

        return preg_replace('/[^A-Za-z0-9]/', '', implode('', $parts)) ?? '';
    }
}
