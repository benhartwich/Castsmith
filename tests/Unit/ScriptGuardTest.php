<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Numbers\ScriptGuard;
use PHPUnit\Framework\TestCase;

final class ScriptGuardTest extends TestCase
{
    public function testCleanScriptPasses(): void
    {
        $script = 'Am dreiundzwanzigsten September um zwei Uhr fünf. <break time="1.5s" /> Weiter geht es.';

        self::assertSame([], ScriptGuard::check($script));
        self::assertFalse(ScriptGuard::isBlocking($script));
    }

    public function testDigitOutsideBreakTagBlocks(): void
    {
        $script = 'Am 23. September um zwei Uhr fünf.';

        self::assertTrue(ScriptGuard::isBlocking($script));
        self::assertNotEmpty(ScriptGuard::digitsOutsideBreaks($script));
    }

    public function testDigitsInsideBreakTagsAreAllowed(): void
    {
        self::assertSame([], ScriptGuard::digitsOutsideBreaks('Text <break time="1.5s" /> Text'));
    }

    public function testUhrNullBlocks(): void
    {
        $script = 'Die Sonne geht um zwanzig Uhr null vier unter.';

        self::assertTrue(ScriptGuard::isBlocking($script));
        self::assertCount(1, ScriptGuard::uhrNullOccurrences($script));
    }

    public function testHourZeroIsNotFlagged(): void
    {
        // "null Uhr sechs" ist laut System Prompt richtig und muss durchgehen.
        $script = 'Der Mond geht um null Uhr sechs auf.';

        self::assertSame([], ScriptGuard::uhrNullOccurrences($script));
        self::assertFalse(ScriptGuard::isBlocking($script));
    }

    public function testTooManyBreakTagsIsOnlyANote(): void
    {
        $script = str_repeat('Text <break time="1.0s" /> ', 7);
        $problems = ScriptGuard::check($script);

        self::assertCount(1, $problems);
        self::assertSame(ScriptGuard::SEVERITY_NOTE, $problems[0]['severity']);
        self::assertFalse(ScriptGuard::isBlocking($script));
        self::assertSame(7, ScriptGuard::breakCount($script));
    }

    public function testSamplesShowTheOffendingPlace(): void
    {
        $problems = ScriptGuard::check('Die Sonne geht am 23. September unter.');

        self::assertStringContainsString('23', $problems[0]['samples'][0]);
    }
}
