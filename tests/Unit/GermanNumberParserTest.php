<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Numbers\GermanNumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Parser ist das Sicherheitsnetz gegen halluzinierte Zahlen. Die Beispiele
 * stammen überwiegend aus echten Folgen des AAS-Podcasts.
 */
final class GermanNumberParserTest extends TestCase
{
    /**
     * @return array<string,array{0:string,1:float}>
     */
    public static function cardinals(): array
    {
        return [
            'null'                  => ['null', 0.0],
            'eins'                  => ['eins', 1.0],
            'ein'                   => ['ein', 1.0],
            'zwei'                  => ['zwei', 2.0],
            'vier'                  => ['vier', 4.0],
            'acht'                  => ['acht', 8.0],
            'zehn'                  => ['zehn', 10.0],
            'elf'                   => ['elf', 11.0],
            'zwoelf'                => ['zwölf', 12.0],
            'dreizehn'              => ['dreizehn', 13.0],
            'sechzehn'              => ['sechzehn', 16.0],
            'neunzehn'              => ['neunzehn', 19.0],
            'zwanzig'               => ['zwanzig', 20.0],
            'dreissig mit ss'       => ['dreissig', 30.0],
            'dreissig mit scharf s' => ['dreißig', 30.0],
            'vierundzwanzig'        => ['vierundzwanzig', 24.0],
            'einundzwanzig'         => ['einundzwanzig', 21.0],
            'fuenfunddreissig'      => ['fünfunddreißig', 35.0],
            'siebenundzwanzig'      => ['siebenundzwanzig', 27.0],
            'neunundvierzig'        => ['neunundvierzig', 49.0],
            'einundfuenfzig'        => ['einundfünfzig', 51.0],
            'hundert'               => ['hundert', 100.0],
            'einhundertneun'        => ['einhundertneun', 109.0],
            'hundertfuenf'          => ['hundertfünf', 105.0],
            'dreihundertsechsundsiebzig' => ['dreihundertsechsundsiebzig', 376.0],
            'achthundert'           => ['achthundert', 800.0],
            'tausend'               => ['tausend', 1000.0],
            'zweitausendsechsundzwanzig' => ['zweitausendsechsundzwanzig', 2026.0],
            'zweitausendacht'       => ['zweitausendacht', 2008.0],
            'zweitausenddreizehn'   => ['zweitausenddreizehn', 2013.0],
            'viertausenddreihundertneunzehn' => ['viertausenddreihundertneunzehn', 4319.0],
            'siebenundzwanzigtausend' => ['siebenundzwanzigtausend', 27000.0],
            'siebenundsechzigtausend' => ['siebenundsechzigtausend', 67000.0],
            'eine Million'          => ['eine Million', 1000000.0],
            'zwei Millionen'        => ['zwei Millionen', 2000000.0],
            'mit Leerzeichen'       => ['einhundertneun Millionen', 109000000.0],
            'Milliarde'             => ['drei Milliarden', 3000000000.0],
            'zusammengesetzt gross' => ['zwei Millionen dreihunderttausend', 2300000.0],
            'Bindestrich'           => ['fünf-und-zwanzig', 25.0],
        ];
    }

    #[DataProvider('cardinals')]
    public function testCardinal(string $words, float $expected): void
    {
        self::assertSame($expected, GermanNumberParser::cardinal($words));
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function ordinals(): array
    {
        return [
            'ersten'                => ['ersten', 1],
            'erste'                 => ['erste', 1],
            'zweiten'               => ['zweiten', 2],
            'dritten'               => ['dritten', 3],
            'vierten'               => ['vierten', 4],
            'sechsten'              => ['sechsten', 6],
            'siebten'               => ['siebten', 7],
            'siebenten'             => ['siebenten', 7],
            'achten'                => ['achten', 8],
            'neunten'               => ['neunten', 9],
            'zehnten'               => ['zehnten', 10],
            'elften'                => ['elften', 11],
            'vierzehnten'           => ['vierzehnten', 14],
            'fuenfzehnten'          => ['fünfzehnten', 15],
            'siebzehnten'           => ['siebzehnten', 17],
            'achtzehnten'           => ['achtzehnten', 18],
            'neunzehnten'           => ['neunzehnten', 19],
            'zwanzigsten'           => ['zwanzigsten', 20],
            'dreiundzwanzigsten'    => ['dreiundzwanzigsten', 23],
            'fuenfundzwanzigsten'   => ['fünfundzwanzigsten', 25],
            'sechsundzwanzigsten'   => ['sechsundzwanzigsten', 26],
            'dreissigsten'          => ['dreißigsten', 30],
            'einunddreissigsten'    => ['einunddreißigsten', 31],
            'ohne Endung'           => ['zwanzigst', 20],
        ];
    }

    #[DataProvider('ordinals')]
    public function testOrdinal(string $words, int $expected): void
    {
        self::assertSame($expected, GermanNumberParser::ordinal($words));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function notNumbers(): array
    {
        return [
            'gewoehnliches Wort'    => ['Mondsichel'],
            'leer'                  => [''],
            'Wort mit und'          => ['Herbst-Tagundnachtgleiche'],
            'Sternbild'             => ['Zwillinge'],
            'Eigenname'             => ['Fomalhaut'],
            'Einheit'               => ['Magnituden'],
            'Fantasiewort'          => ['zwanzigsechs'],
        ];
    }

    #[DataProvider('notNumbers')]
    public function testRejectsNonNumbers(string $words): void
    {
        self::assertNull(GermanNumberParser::cardinal($words), $words . ' ist keine Kardinalzahl');
    }

    public function testOrdinalRejectsPlainCardinal(): void
    {
        // "zwanzig" ist eine Kardinalzahl, keine Ordinalzahl.
        self::assertNull(GermanNumberParser::ordinal('zwanzig'));
    }

    public function testAnyPrefersCardinal(): void
    {
        self::assertSame(8.0, GermanNumberParser::any('acht'));
        self::assertSame(8.0, GermanNumberParser::any('achten'));
    }

    public function testNormalizeFoldsUmlautsAndSeparators(): void
    {
        self::assertSame('fuenfunddreissig', GermanNumberParser::normalize(' Fünf-und-Dreißig '));
    }
}
