<?php

declare(strict_types=1);

namespace Castsmith\Voice;

/**
 * Keeps phonetic transcriptions free of characters that ElevenLabs
 * demonstrably renders incorrectly with this voice.
 *
 * Finding from 31.08.2026, episode 1: of seventeen rules in the dictionary,
 * exactly the six whose transcription contained one of three characters
 * sounded wrong — the two subscript combining characters and the ich-sound.
 * The other eleven rules were flawless, even though they include characters
 * that do not exist in English (ʁ in Eratosthenes, y in Typhon) and even
 * though one of them spans several words and a glottal stop (a club domain name).
 * This rules out model language, spaces and glottal stop as the cause; it is
 * exactly these three characters.
 *
 * @see DECISIONS.md, entry "Lautschrift ohne Kombinationszeichen"
 */
final class PhonemeGuard
{
    /** Non-syllabic, U+032F. Removed without replacement. */
    public const NICHT_SILBISCH = "\u{032F}";

    /** Syllabic, U+0329. The consonant gets a schwa in front of it instead. */
    public const SILBISCH = "\u{0329}";

    /** Ich-sound. In de-AT the ending sound is k anyway ("dreißik"). */
    public const ICH_LAUT = 'ç';

    /**
     * Reports the unusable characters in a phonetic transcription.
     *
     * @return list<string> Plain-text names, empty if the transcription is clean
     */
    public static function pruefe(string $ipa): array
    {
        $gefunden = [];

        foreach ([
            self::NICHT_SILBISCH => 'nicht-silbisches Zeichen ◌̯ (U+032F)',
            self::SILBISCH       => 'silbisches Zeichen ◌̩ (U+0329)',
            self::ICH_LAUT       => 'ich-Laut ç',
        ] as $zeichen => $name) {
            if (mb_strpos($ipa, $zeichen) !== false) {
                $gefunden[] = $name;
            }
        }

        return $gefunden;
    }

    public static function istSauber(string $ipa): bool
    {
        return self::pruefe($ipa) === [];
    }

    /**
     * Rewrites a phonetic transcription into a form the voice can render.
     *
     * The replacements are deliberately mechanical and low-loss: the
     * non-syllabic character is dropped, the syllabic consonant gets a schwa
     * prepended, and the ich-sound becomes the k of Austrian pronunciation.
     * The result is not always the finest transcription, but it sounds
     * right — unlike the original.
     */
    public static function bereinige(string $ipa): string
    {
        // Order matters: first the syllabic character, which inserts a
        // character, then the two that only replace or delete.
        $ipa = (string) preg_replace(
            '/(.)' . preg_quote(self::SILBISCH, '/') . '/u',
            'ə$1',
            $ipa
        );

        $ipa = str_replace(self::NICHT_SILBISCH, '', $ipa);

        return str_replace(self::ICH_LAUT, 'k', $ipa);
    }
}
