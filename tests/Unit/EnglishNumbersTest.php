<?php
declare(strict_types=1);

namespace PodcastForge\Tests\Unit;

use PodcastForge\Numbers\EnglishNumberParser;
use PodcastForge\Numbers\NumberDiff;
use PodcastForge\Numbers\NumberExtractor;
use PHPUnit\Framework\TestCase;

/**
 * The number gate in English: digits in the fact script, words in the spoken
 * script, and nothing may slip through in either direction.
 */
final class EnglishNumbersTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function keys(string $text): array
    {
        return array_map(static fn ($v): string => $v->key, NumberExtractor::extract($text, 'en'));
    }

    /**
     * @return array<string,array{0:string,1:float,2:bool}>
     */
    public static function words(): array
    {
        return [
            'unit'            => ['four', 4, false],
            'teen'            => ['seventeen', 17, false],
            'hyphenated'      => ['twenty-one', 21, false],
            'hundreds and'    => ['three hundred and five', 305, false],
            'thousands'       => ['twenty-seven thousand', 27000, false],
            'complex'         => ['four thousand three hundred nineteen', 4319, false],
            'year'            => ['two thousand twenty-six', 2026, false],
            'a hundred'       => ['a hundred', 100, false],
            'million'         => ['one million', 1000000, false],
            'ordinal'         => ['fourth', 4, true],
            'compound ord.'   => ['twenty-first', 21, true],
            'thirtieth'       => ['thirtieth', 30, true],
        ];
    }

    /**
     * @dataProvider words
     */
    public function testParsesNumberWords(string $text, float $value, bool $ordinal): void
    {
        $parsed = EnglishNumberParser::parseAt(EnglishNumberParser::tokenize($text), 0);

        self::assertNotNull($parsed);
        self::assertSame($value, $parsed['value']);
        self::assertSame($ordinal, $parsed['ordinal']);
    }

    public function testTimesInWordsAndDigitsGiveTheSameKeys(): void
    {
        self::assertSame(['zeit:21:13'], self::keys('at nine thirteen p.m.'));
        self::assertSame(['zeit:21:13'], self::keys('at 9:13 p.m.'));
        self::assertSame(['zeit:21:13'], self::keys('at 21:13'));
        self::assertSame(['zeit:21:04'], self::keys('at nine oh four p.m.'));
        self::assertSame(['zeit:0:06'], self::keys('at twelve oh six a.m.'));
        self::assertSame(['zeit:0:00'], self::keys('around midnight'));
        self::assertSame(['zeit:7:00'], self::keys('at seven a.m.'));
    }

    public function testDatesDecimalsAndLargeNumbers(): void
    {
        self::assertSame(['monat:10', 'tag:4'], self::keys('on October 4th'));
        self::assertSame(['monat:10', 'tag:4'], self::keys('on October fourth'));
        self::assertSame(['zahl:4.8'], self::keys('4.8'));
        self::assertSame(['zahl:4.8'], self::keys('four point eight'));
        self::assertSame(['zahl:-1.5'], self::keys('minus one point five'));
        self::assertSame(['zahl:2500000'], self::keys('two point five million'));
        self::assertSame(['zahl:2500000'], self::keys('2.5 million'));
        self::assertSame(['zahl:27000'], self::keys('27,000'));
        self::assertSame(['zahl:2026'], self::keys('in two thousand twenty-six'));
    }

    public function testAmbiguousWordsAreNotNumbers(): void
    {
        self::assertSame([], self::keys('This one is the brightest, one of the best sights, a comet.'));
        self::assertSame([], self::keys('You may see it with binoculars.'));
        self::assertSame(['zahl:100'], self::keys('one hundred'));
        self::assertSame(['zahl:1'], self::keys('one point zero'));
    }

    public function testFactScriptAgainstSpokenScriptIsClean(): void
    {
        $source = "On October 4, Saturn reaches opposition. Sunset on October 1 is at 18:51, on October 31 at 16:56. "
            . "The Moon passes Mars on the 5th. Vesta shines at 5.8 mag. The meteor shower peaks on October 22 with up to 20 meteors per hour. "
            . "The galaxy is 2.5 million light years away. In 2026 the rings appear narrow.";
        $script = "On October fourth, Saturn reaches opposition. The sun sets on October first at six fifty-one p.m., on October thirty-first at four fifty-six p.m. "
            . "The Moon passes Mars on the fifth. Vesta shines at five point eight magnitudes. The meteor shower peaks on October twenty-second with up to twenty meteors per hour. "
            . "The galaxy is two point five million light years away. In two thousand twenty-six the rings appear narrow.";

        $diff = NumberDiff::compare($source, $script, 'en');

        self::assertSame([], $diff->toArray()['erfunden'], 'invented');
        self::assertSame([], $diff->toArray()['fehlt'], 'missing');
        self::assertSame([], $diff->toArray()['geaendert'] ?? [], 'changed');
    }

    public function testChangedTimeIsCaught(): void
    {
        $diff = NumberDiff::compare('Sunset at 18:51.', 'The sun sets at six fifteen p.m.', 'en');

        self::assertNotSame([], array_merge($diff->toArray()['erfunden'], $diff->toArray()['fehlt'], $diff->toArray()['geaendert'] ?? []));
    }
}
