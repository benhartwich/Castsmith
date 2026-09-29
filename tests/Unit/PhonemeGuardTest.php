<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Voice\PhonemeGuard;
use PHPUnit\Framework\TestCase;

/**
 * Die Fälle stammen aus Episode 1: Genau diese sechs Regeln klangen falsch,
 * genau diese elf klangen richtig.
 */
final class PhonemeGuardTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function fehlerhafteRegeln(): array
    {
        return [
            'Perseiden, silbisches n'      => ["pɛʁzeˈiːdn̩", "pɛʁzeˈiːdən"],
            'Pisciden, silbisches n'       => ["pɪsˈtsiːdn̩", "pɪsˈtsiːdən"],
            'Atair, nicht-silbisches a'    => ["ataˈiːɐ̯", "ataˈiːɐ"],
            'Clear Skies, zweimal'         => ["klɪɐ̯ ˈskaɪ̯s", "klɪɐ ˈskaɪs"],
            'M dreißig, beide Sorten'      => ["ʔɛm ˈdʁaɪ̯sɪç", "ʔɛm ˈdʁaɪsɪk"],
            'M fünfundsiebzig, ich-Laut'   => ["ʔɛm fʏnfʊntˈziːptsɪç", "ʔɛm fʏnfʊntˈziːptsɪk"],
        ];
    }

    /** @return array<string,array{string}> */
    public static function unauffaelligeRegeln(): array
    {
        return [
            'Spica'         => ["ˈspiːka"],
            'Algedi'        => ["alˈɡɛdi"],
            'Fomalhaut'     => ["foːmalˈhuːt"],
            'Wega'          => ["ˈveːɡa"],
            'Deneb'         => ["ˈdeːnɛp"],
            'Encke'         => ["ˈɛŋkə"],
            'Eratosthenes'  => ["eʁaˈtɔstenɛs"],
            'Typhon'        => ["ˈtyːfɔn"],
            'Wasat'         => ["ˈvasat"],
            'Planisphärium' => ["planiˈsfɛːʁiʊm"],
            'Astronomie.at' => ["astronoˈmiː pʊŋkt ʔaː ˈteː"],
        ];
    }

    /** @dataProvider fehlerhafteRegeln */
    public function testBereinigtDieSechsFehlerhaftenRegeln(string $ein, string $soll): void
    {
        self::assertSame($soll, PhonemeGuard::bereinige($ein));
    }

    /** @dataProvider fehlerhafteRegeln */
    public function testErkenntDieSechsFehlerhaftenRegeln(string $ein): void
    {
        self::assertFalse(PhonemeGuard::istSauber($ein));
        self::assertNotSame([], PhonemeGuard::pruefe($ein));
    }

    /** @dataProvider fehlerhafteRegeln */
    public function testDasErgebnisIstSelbstSauber(string $ein): void
    {
        self::assertTrue(PhonemeGuard::istSauber(PhonemeGuard::bereinige($ein)));
    }

    /** @dataProvider unauffaelligeRegeln */
    public function testLaesstFunktionierendeRegelnUnberuehrt(string $ipa): void
    {
        self::assertTrue(PhonemeGuard::istSauber($ipa));
        self::assertSame($ipa, PhonemeGuard::bereinige($ipa));
    }

    public function testMeldetJedesZeichenEinzeln(): void
    {
        self::assertCount(3, PhonemeGuard::pruefe("aɪ̯ n̩ ç"));
        self::assertCount(1, PhonemeGuard::pruefe("ˈdʁaɪsɪç"));
    }

    public function testSchwaKommtVorDenKonsonanten(): void
    {
        self::assertSame('əl', PhonemeGuard::bereinige("l̩"));
        self::assertSame('əm', PhonemeGuard::bereinige("m̩"));
        self::assertSame('ən', PhonemeGuard::bereinige("n̩"));
    }

    public function testLeereLautschriftBleibtLeer(): void
    {
        self::assertSame('', PhonemeGuard::bereinige(''));
        self::assertTrue(PhonemeGuard::istSauber(''));
    }

    public function testCoarseningDependsOnTheModel(): void
    {
        $precise = 'ʔɛm ˈdʁaɪ̯sɪç';
        self::assertSame($precise, PhonemeGuard::fuerModell($precise, 'eleven_v4'));
        self::assertSame(PhonemeGuard::bereinige($precise), PhonemeGuard::fuerModell($precise, 'eleven_v3'));
        self::assertNotSame($precise, PhonemeGuard::fuerModell($precise, 'eleven_v3'));
    }
}
