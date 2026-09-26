<?php
declare(strict_types=1);

namespace PodcastForge\Ai;

/**
 * List prices of the Anthropic API in US dollars per million tokens.
 *
 * A rough estimate, not billing — usage is counted per run so that an
 * accidentally repeated run stands out. Until 25.09.2026 the plugin
 * applied Opus 5 prices across the board and displayed the result in euros;
 * both were wrong.
 *
 * Surcharges and discounts as published: cache writes 1.25x, cache reads
 * 0.1x, batch 50 % off everything.
 */
final class Pricing
{
    /** @var array<string,array{0:float,1:float}> Model prefix => [input, output] */
    private const RATES = [
        'claude-opus-5-5'  => [4.0, 20.0],
        'claude-opus-5'    => [5.0, 25.0],
        'claude-sonnet-5'  => [2.0, 10.0],
        'claude-fable-5'   => [10.0, 50.0],
        'claude-haiku-4-5' => [1.0, 5.0],
    ];

    public static function cents(string $model, int $input, int $output, int $cacheWrite = 0, int $cacheRead = 0, bool $batch = false): float
    {
        [$in, $out] = self::rates($model);

        $usd = ($input * $in + $cacheWrite * $in * 1.25 + $cacheRead * $in * 0.1 + $output * $out) / 1000000;
        if ($batch) {
            $usd *= 0.5;
        }

        return round($usd * 100, 2);
    }

    /**
     * @return array{0:float,1:float}
     */
    public static function rates(string $model): array
    {
        // Longest matching prefix first: "claude-opus-5-5" before "claude-opus-5".
        $best = null;
        foreach (self::RATES as $prefix => $rates) {
            if (str_starts_with($model, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best !== null ? self::RATES[$best] : self::RATES['claude-opus-5'];
    }
}
