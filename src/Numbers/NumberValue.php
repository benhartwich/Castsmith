<?php
declare(strict_types=1);

namespace Castsmith\Numbers;

/**
 * A typed numeric value from a fact script or a spoken script.
 *
 * Comparison is done on typed values, not raw digits. Otherwise the
 * comparison fails on representations that necessarily differ:
 * "23. September" versus "dreiundzwanzigsten September", or
 * "2 Uhr 05" versus "zwei Uhr fünf".
 */
final class NumberValue
{
    public const TIME    = 'zeit';
    public const DAY     = 'tag';
    public const MONTH   = 'monat';
    public const NUMBER  = 'zahl';

    private function __construct(
        public readonly string $kind,
        public readonly string $key,
        public readonly string $label,
        public readonly string $context,
        public readonly ?float $numeric = null
    ) {
    }

    public static function time(int $hour, int $minute, string $context = ''): self
    {
        return new self(
            self::TIME,
            sprintf('zeit:%d:%02d', $hour, $minute),
            sprintf('%d:%02d Uhr', $hour, $minute),
            $context
        );
    }

    public static function day(int $day, string $context = ''): self
    {
        return new self(self::DAY, 'tag:' . $day, $day . '.', $context);
    }

    public static function month(int $month, string $context = ''): self
    {
        return new self(self::MONTH, 'monat:' . $month, self::MONTH_NAMES[$month] ?? (string) $month, $context);
    }

    public static function number(float $value, string $context = ''): self
    {
        // Write whole numbers without a decimal part so that 40 and 40.0
        // produce the same key.
        $canonical = floor($value) === $value && abs($value) < 1.0e15
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return new self(self::NUMBER, 'zahl:' . $canonical, $canonical, $context, $value);
    }

    /** @var array<int,string> */
    public const MONTH_NAMES = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April',
        5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    /**
     * Month names including the Austrian variants.
     *
     * "Jänner" instead of "Januar" is explicitly required in the system prompt.
     *
     * @return array<string,int>
     */
    public static function monthLookup(string $language = 'de'): array
    {
        if ($language === 'en') {
            return [
                'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
                'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            ];
        }

        return [
            'januar' => 1, 'jaenner' => 1, 'jänner' => 1,
            'februar' => 2, 'feber' => 2,
            'maerz' => 3, 'märz' => 3,
            'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
            'august' => 8, 'september' => 9, 'oktober' => 10,
            'november' => 11, 'dezember' => 12,
        ];
    }
}
