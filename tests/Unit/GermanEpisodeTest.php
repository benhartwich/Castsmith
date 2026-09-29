<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Numbers\NumberDiff;
use Sonoquill\Numbers\NumberExtractor;
use Sonoquill\Numbers\ScriptGuard;
use PHPUnit\Framework\TestCase;

/**
 * The German number gate on a complete fact script and its spoken version:
 * times, dates, decimals, thousands, years and ordinals in both forms.
 */
final class GermanEpisodeTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/' . $name);
    }

    public function testSpokenScriptPassesTheGuard(): void
    {
        $script = $this->fixture('de_sprechskript.txt');

        self::assertSame([], ScriptGuard::check($script));
        self::assertSame(2, ScriptGuard::breakCount($script));
    }

    public function testEveryNumberOfTheSourceIsSpokenAndNothingIsAdded(): void
    {
        $result = NumberDiff::compare($this->fixture('de_faktenskript.txt'), $this->fixture('de_sprechskript.txt'));

        self::assertSame([], $result->toArray()['erfunden'], 'invented');
        self::assertSame([], $result->toArray()['fehlt'], 'missing');
        self::assertFalse($result->isBlocking());
        self::assertGreaterThanOrEqual(25, count(NumberExtractor::extract($this->fixture('de_faktenskript.txt'))));
    }

    public function testAChangedTimeBlocks(): void
    {
        $script = str_replace('achtzehn Uhr dreißig', 'achtzehn Uhr vierzig', $this->fixture('de_sprechskript.txt'));

        self::assertTrue(NumberDiff::compare($this->fixture('de_faktenskript.txt'), $script)->isBlocking());
    }

    public function testColonAndDotTimesAreTimes(): void
    {
        $keys = static fn (string $t): array => array_map(static fn ($v): string => $v->key, NumberExtractor::extract($t));

        self::assertSame(['zeit:18:30'], $keys('um 18:30 Uhr'));
        self::assertSame(['zeit:18:30'], $keys('um 18.30 Uhr'));
        self::assertSame(['zeit:20:15'], $keys('Anpfiff 20:15'));
        self::assertSame(['zahl:18.3'], $keys('ein Wert von 18.30'));
        self::assertSame(['tag:3', 'monat:10'], $keys('am 3.10.'));
    }
}
