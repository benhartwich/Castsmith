<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Numbers\NumberExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Beispiele sind wörtlich aus dem Faktenskript und dem Sprechskript der
 * Folge September 2026 entnommen.
 */
final class NumberExtractorTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    private function assertKeys(array $expected, string $text): void
    {
        $keys = array_map(
            static fn ($v): string => $v->key,
            NumberExtractor::extract($text)
        );

        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys, 'Text: ' . $text);
    }

    /**
     * @return array<string,array{0:list<string>,1:string}>
     */
    public static function ziffernSeite(): array
    {
        return [
            'Uhrzeit mit Minute'      => [['zeit:2:05'], 'um 2 Uhr 05'],
            'Uhrzeit mit fuehrender Null' => [['zeit:20:04'], 'um 20 Uhr 04'],
            'Uhrzeit ohne Minute'     => [['zeit:2:00'], 'gegen 2 Uhr'],
            'Satzende hinter Minute'  => [['zeit:2:52'], 'schon um 2 Uhr 52.'],
            'Tag mit Monat'           => [['tag:23', 'monat:9'], 'Am 23. September'],
            'Tag aus dem Zusammenhang'=> [['zahl:6', 'tag:17'], 'Sechs Tage vorher, am 17., wechselt sie'],
            'Tag nach des'            => [['tag:14'], 'am Abend des 14.'],
            'Tag nach bis zum'        => [['tag:30'], 'bis zum 30.'],
            'Zahl mit Punkt ist kein Tag' => [['zahl:105'], 'der andere nur 105.'],
            'Dezimalzahl negativ'     => [['zahl:-4.8'], 'mit minus 4,8 Magnituden'],
            'Dezimalzahl positiv'     => [['zahl:0.4'], 'auf 0,4 Magnituden'],
            'zwei Nachkommastellen'   => [['zahl:28.87'], 'das sind 28,87 Astronomische Einheiten'],
            'Groessenordnung'         => [['zahl:109000000'], '109 Millionen Kilometer'],
            'Groessenordnung gross'   => [['zahl:4319000000'], 'uns 4319 Millionen Kilometer'],
            'Tausendergliederung'     => [['zahl:27000'], 'in 27.000 Lichtjahren'],
            'Katalognummer'           => [['zahl:30'], 'Kugelsternhaufen M 30'],
            'Komet'                   => [['zahl:2'], 'Kometen 2P/Encke'],
            'Jahreszahl'              => [['zahl:2026', 'monat:9'], 'Der September 2026 bringt'],
            'zwei Jahreszahlen'       => [['zahl:2008', 'zahl:2013'], 'in den Jahren 2008 und 2013'],
            'numerisches Datum'       => [['tag:22', 'monat:9', 'zahl:2026'], 'am 22.09.2026'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('ziffernSeite')]
    public function testDigitSide(array $expected, string $text): void
    {
        $this->assertKeys($expected, $text);
    }

    /**
     * @return array<string,array{0:list<string>,1:string}>
     */
    public static function wortSeite(): array
    {
        return [
            'Uhrzeit'                 => [['zeit:2:05'], 'um zwei Uhr fünf'],
            'Uhrzeit einstellig'      => [['zeit:20:04'], 'um zwanzig Uhr vier'],
            'Uhrzeit mit ein'         => [['zeit:1:14'], 'um ein Uhr vierzehn'],
            'Uhrzeit ohne Minute'     => [['zeit:5:00'], 'gegen fünf Uhr morgens'],
            'Stunde null'             => [['zeit:0:06'], 'um null Uhr sechs'],
            'Tag mit Monat'           => [['tag:23', 'monat:9'], 'Am dreiundzwanzigsten September'],
            'Tag allein'              => [['tag:11'], 'Neumond am elften'],
            'Dezimalzahl negativ'     => [['zahl:-4.8'], 'mit minus vier Komma acht Magnituden'],
            'Dezimalzahl eins'        => [['zahl:1.1'], 'Mit eins Komma eins Magnituden'],
            'Dezimalzahl null'        => [['zahl:0.4'], 'auf null Komma vier Magnituden'],
            'Nachkomma zweistellig'   => [['zahl:28.87'], 'achtundzwanzig Komma siebenundachtzig Einheiten'],
            'Nachkomma ziffernweise'  => [['zahl:28.87'], 'achtundzwanzig Komma acht sieben Einheiten'],
            'Groessenordnung'         => [['zahl:109000000'], 'einhundertneun Millionen Kilometer'],
            'Katalogname'             => [['zahl:30'], 'Kugelsternhaufen M dreißig'],
            'Komet'                   => [['zahl:2'], 'Kometen zwei P Encke'],
            'Jahreszahl'              => [['zahl:2026', 'monat:9'], 'Der September zweitausendsechsundzwanzig'],
            'und verbindet zwei Zahlen' => [['zahl:2008', 'zahl:2013'], 'in den Jahren zweitausendacht und zweitausenddreizehn'],
            'unbestimmter Artikel zaehlt nicht' => [[], 'zu einer neuen Ausgabe'],
            'ein als Artikel'         => [[], 'nur ein halbes Grad auseinander'],
            'Millionen allein'        => [[], 'Millionen von Kilometern'],
            'Quelle schreibt aus'     => [['zahl:6', 'tag:17'], 'Sechs Tage vorher, am 17., wechselt sie'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('wortSeite')]
    public function testSpokenSide(array $expected, string $text): void
    {
        $this->assertKeys($expected, $text);
    }

    public function testBreakTagsAreIgnored(): void
    {
        $this->assertKeys([], '<break time="1.5s" />');
        $this->assertKeys(['zahl:7'], 'sieben <break time="1.0s" /> Grad');
    }

    public function testCountedAggregates(): void
    {
        $counts = NumberExtractor::counted('Am 1. September und am 1. Oktober');

        self::assertSame(2, $counts['tag:1']);
        self::assertSame(1, $counts['monat:9']);
        self::assertSame(1, $counts['monat:10']);
    }

    public function testAustrianMonthName(): void
    {
        $this->assertKeys(['tag:20', 'monat:1'], 'am 20. Jänner');
        $this->assertKeys(['tag:20', 'monat:1'], 'am zwanzigsten Jänner');
    }
}
