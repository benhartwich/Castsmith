<?php
declare(strict_types=1);

namespace Sonoquill\Voice;

/**
 * What the ElevenLabs speech models can do, in one place.
 *
 * ElevenLabs does not report these capabilities through the API; they come
 * from the documentation and were verified by requests where the difference
 * matters (see the individual lists).
 */
final class Models
{
    /**
     * Models that evaluate phoneme rules of a pronunciation dictionary at
     * all. Other models skip them without an error.
     */
    private const PHONEME = ['eleven_flash_v2', 'eleven_v3', 'eleven_v3_conversational', 'eleven_v4'];

    /**
     * Models that evaluate phoneme rules beyond English. The documentation
     * names Eleven v4 for IPA in other languages; Eleven v3 was measured to
     * do it too (a phonetic transcription lengthened a German sentence by a
     * full second, Multilingual v2 changed nothing).
     */
    private const MULTILINGUAL_PHONEME = ['eleven_v3', 'eleven_v3_conversational', 'eleven_v4'];

    /**
     * Models that reject `previous_text` and `next_text`. Eleven v3 answers
     * with HTTP 400 ("not yet supported"); Eleven v4 accepts them (measured
     * on 29.09.2026).
     */
    private const WITHOUT_NEIGHBOUR_TEXT = ['eleven_v3', 'eleven_v3_conversational'];

    /**
     * Models that render three IPA characters wrongly (◌̯, ◌̩, ç — see
     * PhonemeGuard). Eleven v4 renders them correctly (listening test with
     * all 58 dictionary terms, 29.09.2026), so there the transcription stays
     * as precise as it was written.
     */
    private const COARSE_IPA = ['eleven_v3', 'eleven_v3_conversational'];

    public static function evaluatesPhonemes(string $modelId): bool
    {
        return in_array($modelId, self::PHONEME, true);
    }

    public static function evaluatesPhonemesBeyondEnglish(string $modelId): bool
    {
        return in_array($modelId, self::MULTILINGUAL_PHONEME, true);
    }

    public static function needsCoarseIpa(string $modelId): bool
    {
        return in_array($modelId, self::COARSE_IPA, true);
    }

    public static function acceptsNeighbourText(string $modelId): bool
    {
        return !in_array($modelId, self::WITHOUT_NEIGHBOUR_TEXT, true);
    }
}
