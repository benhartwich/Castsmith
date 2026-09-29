<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Voice\Models;
use PHPUnit\Framework\TestCase;

/**
 * Which model evaluates phonetic transcription decides the rule type the
 * dictionary gets — a wrong entry means rules that are silently ignored.
 */
final class ModelsTest extends TestCase
{
    public function testPhonemeRulesBeyondEnglish(): void
    {
        foreach (['eleven_v4', 'eleven_v3', 'eleven_v3_conversational'] as $model) {
            self::assertTrue(Models::evaluatesPhonemesBeyondEnglish($model), $model);
        }
        foreach (['eleven_multilingual_v2', 'eleven_flash_v2', 'eleven_flash_v2_5', 'eleven_v4_turbo'] as $model) {
            self::assertFalse(Models::evaluatesPhonemesBeyondEnglish($model), $model);
        }
    }

    public function testPhonemeRulesAtAll(): void
    {
        self::assertTrue(Models::evaluatesPhonemes('eleven_flash_v2'));
        self::assertTrue(Models::evaluatesPhonemes('eleven_v4'));
        self::assertFalse(Models::evaluatesPhonemes('eleven_multilingual_v2'));
    }

    public function testNeighbourText(): void
    {
        self::assertTrue(Models::acceptsNeighbourText('eleven_v4'));
        self::assertFalse(Models::acceptsNeighbourText('eleven_v3'));
    }
}
