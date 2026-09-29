<?php
declare(strict_types=1);

namespace Sonoquill\Segments;

use Sonoquill\Voice\VoiceSettings;

/**
 * Decides whether a segment has to be regenerated.
 *
 * The hash covers text, seed, dictionary state and voice settings.
 * For the dictionary, the version identifier is deliberately not included;
 * instead we use the fingerprint of exactly those rules that actually apply
 * to this segment text. Otherwise every dictionary edit would regenerate the
 * entire episode instead of only the segments that contain the affected
 * term — the difference between ten cents and two euros per correction round.
 */
final class SegmentHash
{
    /**
     * Which graphemes actually match in this text.
     *
     * By default ElevenLabs matches case-sensitively and only at word
     * boundaries. Both behaviours are replicated here so that the fingerprint
     * hits the same subset as the service does.
     *
     * @param list<string> $graphemes
     *
     * @return list<string>
     */
    public static function applicableGraphemes(string $text, array $graphemes): array
    {
        $hits = [];

        foreach ($graphemes as $grapheme) {
            if ($grapheme === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($grapheme, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $text) === 1) {
                $hits[] = $grapheme;
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * The rules that apply to this text — together with their pronunciation.
     *
     * @param list<array{grapheme:string,type:string,value:string}> $rules
     *
     * @return list<array{grapheme:string,type:string,value:string}>
     */
    public static function applicableRules(string $text, array $rules): array
    {
        $hits = [];

        foreach ($rules as $rule) {
            $grapheme = (string) ($rule['grapheme'] ?? '');
            if ($grapheme === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($grapheme, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $text) === 1) {
                $hits[] = [
                    'grapheme' => $grapheme,
                    'type'     => (string) ($rule['type'] ?? ''),
                    'value'    => (string) ($rule['value'] ?? ''),
                ];
            }
        }

        usort($hits, static fn (array $a, array $b): int => [$a['grapheme'], $a['type'], $a['value']] <=> [$b['grapheme'], $b['type'], $b['value']]);

        return $hits;
    }

    /**
     * The fingerprint covers grapheme AND pronunciation.
     *
     * Hashing only the grapheme names would be a silent bug: if you change
     * the pronunciation of an existing term, the fingerprint would stay the
     * same, the affected segment would be considered up to date and would
     * keep its old audio with the old, wrong pronunciation. This is exactly
     * what surfaced when the Fomalhaut rule was changed.
     *
     * @param list<array{grapheme:string,type:string,value:string}> $rules
     */
    public static function dictionaryFingerprint(string $text, array $rules): string
    {
        $applicable = self::applicableRules($text, $rules);

        if ($applicable === []) {
            return 'leer';
        }

        $parts = array_map(
            static fn (array $r): string => $r['grapheme'] . "\x1e" . $r['type'] . "\x1e" . $r['value'],
            $applicable
        );

        return substr(hash('sha256', implode("\x1f", $parts)), 0, 32);
    }

    /**
     * @param list<array{grapheme:string,type:string,value:string}> $rules
     */
    public static function compute(
        string $text,
        int $seed,
        string $modelId,
        VoiceSettings $voice,
        array $rules
    ): string {
        return hash('sha256', implode("\x1f", [
            $text,
            (string) $seed,
            $modelId,
            $voice->fingerprint(),
            self::dictionaryFingerprint($text, $rules),
        ]));
    }
}
