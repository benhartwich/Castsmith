<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Support\PlsDocument;
use Castsmith\Voice\DictionaryWriter;
use PHPUnit\Framework\TestCase;

/**
 * Nur der Teil, der ohne WordPress und ohne Netz läuft: das Einhängen einer
 * Regel in eine bestehende PLS-Datei.
 */
final class DictionaryWriterTest extends TestCase
{
    private const LEXICON = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<lexicon version="1.0" xmlns="http://www.w3.org/2005/01/pronunciation-lexicon" alphabet="ipa" xml:lang="de-AT">' . "\n"
        . "  <lexeme>\n    <grapheme>Spica</grapheme>\n    <alias>Sspieka</alias>\n  </lexeme>\n"
        . "</lexicon>\n";

    public function testAddsALexemeAndKeepsTheExistingOnes(): void
    {
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Algedi', 'Algeddi');
        $document = PlsDocument::fromString($xml);

        self::assertSame(['Spica', 'Algedi'], $document->graphemes());
        self::assertSame(2, $document->ruleCount());
    }

    public function testResultStaysValidXml(): void
    {
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Wega', 'Wehga');

        self::assertStringContainsString('</lexicon>', $xml);
        self::assertSame(2, PlsDocument::fromString($xml)->ruleCount());
    }

    public function testSeveralRulesCanBeAddedInSequence(): void
    {
        $xml = self::LEXICON;
        foreach (['Deneb' => 'Deneb', 'Atair' => 'Atajr', 'Wasat' => 'Wassat'] as $grapheme => $alias) {
            $xml = DictionaryWriter::appendLexeme($xml, $grapheme, $alias);
        }

        self::assertSame(['Spica', 'Deneb', 'Atair', 'Wasat'], PlsDocument::fromString($xml)->graphemes());
    }

    public function testTheOtherFormIsKeptAsAComment(): void
    {
        // Die jeweils nicht verwendete Form wird mitgeschrieben, damit ein
        // Modellwechsel keine Nacharbeit bedeutet.
        $alias = DictionaryWriter::appendLexeme(self::LEXICON, 'Algedi', 'Algeddi', 'alias', 'alˈɡɛdi');
        self::assertStringContainsString('IPA: alˈɡɛdi', $alias);
        self::assertSame(2, PlsDocument::fromString($alias)->ruleCount(), 'Der Kommentar darf keine Regel werden.');

        $phonem = DictionaryWriter::appendLexeme(self::LEXICON, 'Algedi', 'alˈɡɛdi', 'phoneme', 'Algeddi');
        self::assertStringContainsString('Alias: Algeddi', $phonem);
        self::assertSame(1, PlsDocument::fromString($phonem)->phonemeRuleCount());
    }

    public function testPhonemeRulesAreWrittenAsPhonemeElements(): void
    {
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Perseiden', 'pɛʁzeˈiːdn̩', 'phoneme');

        self::assertStringContainsString('<phoneme alphabet="ipa">pɛʁzeˈiːdn̩</phoneme>', $xml);
        $document = PlsDocument::fromString($xml);
        self::assertSame(['Perseiden'], $document->phonemeGraphemes());
        self::assertSame(1, $document->aliasRuleCount(), 'Die bestehende Alias-Regel bleibt unangetastet.');
    }

    public function testSpecialCharactersAreEscaped(): void
    {
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'A & B', 'A und B');

        self::assertStringContainsString('A &amp; B', $xml);
        self::assertSame(['Spica', 'A & B'], PlsDocument::fromString($xml)->graphemes());
    }

    public function testDoubleHyphenInIpaDoesNotBreakTheComment(): void
    {
        // "--" ist in XML-Kommentaren verboten und machte die Datei unlesbar.
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Test', 'Tesst', 'alias', 'a--b');

        self::assertSame(2, PlsDocument::fromString($xml)->ruleCount());
    }

    public function testDetectsAnExistingGrapheme(): void
    {
        self::assertTrue(DictionaryWriter::hasGrapheme(self::LEXICON, 'Spica'));
        self::assertFalse(DictionaryWriter::hasGrapheme(self::LEXICON, 'Algedi'));
    }

    public function testRemovesALexeme(): void
    {
        $xml = DictionaryWriter::removeLexeme(self::LEXICON, 'Spica');

        self::assertSame([], PlsDocument::fromString($xml)->graphemes());
        self::assertStringContainsString('</lexicon>', $xml);
    }

    public function testRemovingOneKeepsTheOthers(): void
    {
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Algedi', 'Algeddi');
        $xml = DictionaryWriter::appendLexeme($xml, 'Wega', 'Wehga');

        $xml = DictionaryWriter::removeLexeme($xml, 'Algedi');

        self::assertSame(['Spica', 'Wega'], PlsDocument::fromString($xml)->graphemes());
    }

    public function testRemovingSomethingAbsentChangesNothing(): void
    {
        self::assertSame(
            PlsDocument::fromString(self::LEXICON)->graphemes(),
            PlsDocument::fromString(DictionaryWriter::removeLexeme(self::LEXICON, 'GibtEsNicht'))->graphemes()
        );
    }

    public function testReplacingYieldsExactlyOneRule(): void
    {
        // Der Anlass: für Fomalhaut gab es bereits eine Regel. Zwei Regeln für
        // denselben Begriff wären nicht vorhersagbar.
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Fomalhaut', 'Fohmalhaut');
        $xml = DictionaryWriter::appendLexeme(
            DictionaryWriter::removeLexeme($xml, 'Fomalhaut'),
            'Fomalhaut',
            'Fomal Haut'
        );

        $document = PlsDocument::fromString($xml);
        self::assertSame(['Spica', 'Fomalhaut'], $document->graphemes());
        self::assertSame(2, $document->ruleCount(), 'Nur eine Regel je Begriff.');
        self::assertStringContainsString('Fomal Haut', $xml);
        self::assertStringNotContainsString('Fohmalhaut', $xml);
    }

    public function testRemovalDoesNotMatchASubstring(): void
    {
        $xml = DictionaryWriter::appendLexeme(self::LEXICON, 'Wega', 'Wehga');
        $xml = DictionaryWriter::appendLexeme($xml, 'Wegaspur', 'Wehgaspur');

        $xml = DictionaryWriter::removeLexeme($xml, 'Wega');

        self::assertSame(['Spica', 'Wegaspur'], PlsDocument::fromString($xml)->graphemes());
    }

    public function testBuildsAPhonemeDocument(): void
    {
        $xml = DictionaryWriter::buildDocument([
            ['grapheme' => 'Wega', 'type' => 'phoneme', 'value' => 'ˈveːɡa'],
            ['grapheme' => 'Deneb', 'type' => 'phoneme', 'value' => 'ˈdeːnɛp'],
        ]);

        $document = PlsDocument::fromString($xml);
        self::assertSame(['Wega', 'Deneb'], $document->graphemes());
        self::assertSame(2, $document->phonemeRuleCount());
        self::assertSame(0, $document->aliasRuleCount());
        self::assertStringContainsString('alphabet="ipa"', $xml);
    }

    public function testBuildsAMixedDocument(): void
    {
        $xml = DictionaryWriter::buildDocument([
            ['grapheme' => 'Wega', 'type' => 'phoneme', 'value' => 'ˈveːɡa'],
            ['grapheme' => 'Spica', 'type' => 'alias', 'value' => 'Sspieka'],
        ]);

        $document = PlsDocument::fromString($xml);
        self::assertSame(1, $document->phonemeRuleCount());
        self::assertSame(1, $document->aliasRuleCount());
        self::assertSame(['Wega'], $document->phonemeGraphemes());
    }

    public function testIncompleteRulesAreSkipped(): void
    {
        $xml = DictionaryWriter::buildDocument([
            ['grapheme' => '', 'type' => 'phoneme', 'value' => 'ˈveːɡa'],
            ['grapheme' => 'Deneb', 'type' => 'phoneme', 'value' => '   '],
            ['grapheme' => 'Wasat', 'type' => 'phoneme', 'value' => 'ˈvasat'],
        ]);

        self::assertSame(['Wasat'], PlsDocument::fromString($xml)->graphemes());
    }

    public function testIpaWithSpacesSurvives(): void
    {
        // "M dreißig" und "Clear Skies" brauchen Leerzeichen in der Lautschrift.
        $xml = DictionaryWriter::buildDocument([
            ['grapheme' => 'M dreißig', 'type' => 'phoneme', 'value' => 'ʔɛm ˈdʁaɪ̯sɪç'],
        ]);

        $rules = PlsDocument::fromString($xml)->rules();
        self::assertSame('M dreißig', $rules[0]['grapheme']);
        self::assertSame('ʔɛm ˈdʁaɪ̯sɪç', $rules[0]['value']);
    }

    public function testDocumentStaysValidWithSpecialCharacters(): void
    {
        $xml = DictionaryWriter::buildDocument([
            ['grapheme' => 'A & B', 'type' => 'alias', 'value' => 'A und B'],
        ]);

        self::assertSame(['A & B'], PlsDocument::fromString($xml)->graphemes());
    }

    public function testMissingRootElementIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        DictionaryWriter::appendLexeme('<lexicon>', 'X', 'Y');
    }

    /**
     * Der Fehler, der die beiden Messier-Regeln überleben ließ.
     *
     * `replaceAll()` hat den alten Stand erst NACH dem Überschreiben der
     * lokalen Datei gelesen und damit den neuen Satz für den alten gehalten.
     * Begriffe, die aus dem Satz fallen, wurden deshalb nie beim Dienst
     * entfernt. Die Entfernungsmenge kommt jetzt aus beiden Quellen.
     */
    public function testRemovalSetCoversTermsThatOnlyTheServiceStillKnows(): void
    {
        $lokalNeu = self::lexicon(['Spica', 'M']);
        $beimDienst = self::lexicon(['Spica', 'M dreißig', 'M fünfundsiebzig']);

        $zuEntfernen = DictionaryWriter::obsoleteGraphemes($lokalNeu, $beimDienst);

        self::assertContains('M dreißig', $zuEntfernen);
        self::assertContains('M fünfundsiebzig', $zuEntfernen);
        self::assertContains('Spica', $zuEntfernen);
        self::assertContains('M', $zuEntfernen);
    }

    public function testRemovalSetListsEveryTermOnlyOnce(): void
    {
        $zuEntfernen = DictionaryWriter::obsoleteGraphemes(
            self::lexicon(['Spica', 'Wega']),
            self::lexicon(['Wega', 'Deneb'])
        );

        self::assertSame(['Spica', 'Wega', 'Deneb'], $zuEntfernen);
    }

    public function testUnreadableOrEmptySourcesAreSkipped(): void
    {
        // Ein misslungener Abruf darf den Austausch nicht verhindern.
        $zuEntfernen = DictionaryWriter::obsoleteGraphemes('', 'kein XML', self::lexicon(['Spica']));

        self::assertSame(['Spica'], $zuEntfernen);
    }

    /**
     * @param list<string> $graphemes
     */
    private static function lexicon(array $graphemes): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<lexicon version="1.0" xmlns="http://www.w3.org/2005/01/pronunciation-lexicon" alphabet="ipa" xml:lang="de-AT">' . "\n";

        foreach ($graphemes as $grapheme) {
            $xml .= "  <lexeme>\n    <grapheme>" . $grapheme . "</grapheme>\n    <alias>x</alias>\n  </lexeme>\n";
        }

        return $xml . "</lexicon>\n";
    }
}
