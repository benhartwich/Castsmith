<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Support\PlsDocument;
use Castsmith\Support\PlsException;
use PHPUnit\Framework\TestCase;

final class PlsDocumentTest extends TestCase
{
    private const NS = 'http://www.w3.org/2005/01/pronunciation-lexicon';

    private function lexicon(string $body, bool $withNamespace = true): string
    {
        $ns = $withNamespace ? ' xmlns="' . self::NS . '"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<lexicon version="1.0"' . $ns . ' alphabet="ipa" xml:lang="de-AT">'
            . $body
            . '</lexicon>';
    }

    public function testReadsPhonemeAndAliasRules(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            '<lexeme><grapheme>Kahlenberg</grapheme><phoneme>ˈɡaːbɛɐ̯k</phoneme></lexeme>'
            . '<lexeme><grapheme>Algedi</grapheme><alias>Alghedi</alias></lexeme>'
        ));

        self::assertSame(['Kahlenberg', 'Algedi'], $doc->graphemes());
        self::assertSame(2, $doc->ruleCount());
        self::assertSame(1, $doc->phonemeRuleCount());
        self::assertSame(1, $doc->aliasRuleCount());
        self::assertSame(['Kahlenberg'], $doc->phonemeGraphemes());
    }

    public function testWorksWithoutTheLexiconNamespace(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            '<lexeme><grapheme>Mimas</grapheme><alias>Mimass</alias></lexeme>',
            false
        ));

        self::assertSame(['Mimas'], $doc->graphemes());
    }

    public function testKeepsCasingBecauseMatchingIsCaseSensitive(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            '<lexeme><grapheme>Kahlenberg</grapheme><alias>Kahlenberk</alias></lexeme>'
            . '<lexeme><grapheme>kahlenberg</grapheme><alias>gahberk</alias></lexeme>'
        ));

        self::assertSame(['Kahlenberg', 'kahlenberg'], $doc->graphemes());
        self::assertSame(2, $doc->ruleCount());
    }

    public function testLexemeWithSeveralGraphemesYieldsOneRuleEach(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            '<lexeme>'
            . '<grapheme>Zubenelgenubi</grapheme>'
            . '<grapheme>Zuben Elgenubi</grapheme>'
            . '<alias>Subenelgenubi</alias>'
            . '</lexeme>'
        ));

        self::assertSame(['Zubenelgenubi', 'Zuben Elgenubi'], $doc->graphemes());
        self::assertSame(2, $doc->ruleCount());
    }

    public function testTrimsWhitespaceAroundValues(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            "<lexeme>\n  <grapheme>  Rigel  </grapheme>\n  <alias> Rejgel </alias>\n</lexeme>"
        ));

        self::assertSame(['Rigel'], $doc->graphemes());
    }

    public function testGraphemesAreDeduplicated(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            '<lexeme><grapheme>Ceres</grapheme><alias>Zeres</alias><phoneme>ˈtseːʁɛs</phoneme></lexeme>'
        ));

        self::assertSame(['Ceres'], $doc->graphemes());
        self::assertSame(2, $doc->ruleCount(), 'Zwei Aussprachen zu einem Graphem sind zwei Regeln.');
    }

    public function testLexemeWithoutPronunciationIsIgnored(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(
            '<lexeme><grapheme>OhneAussprache</grapheme></lexeme>'
            . '<lexeme><grapheme>Deneb</grapheme><alias>Deneb</alias></lexeme>'
        ));

        self::assertSame(['Deneb'], $doc->graphemes());
    }

    public function testLexemeWithoutGraphemeIsIgnored(): void
    {
        $doc = PlsDocument::fromString($this->lexicon('<lexeme><alias>verwaist</alias></lexeme>'));

        self::assertSame([], $doc->graphemes());
        self::assertSame(0, $doc->ruleCount());
    }

    public function testEmptyLexiconYieldsNoRules(): void
    {
        $doc = PlsDocument::fromString($this->lexicon(''));

        self::assertSame([], $doc->graphemes());
        self::assertSame(0, $doc->ruleCount());
        self::assertSame(0, $doc->phonemeRuleCount());
    }

    public function testMalformedXmlIsRejected(): void
    {
        $this->expectException(PlsException::class);
        $this->expectExceptionMessageMatches('/not valid XML/');
        PlsDocument::fromString('<lexicon><lexeme><grapheme>kaputt</lexicon>');
    }

    public function testEmptyInputIsRejected(): void
    {
        $this->expectException(PlsException::class);
        $this->expectExceptionMessageMatches('/empty/');
        PlsDocument::fromString("   \n ");
    }

    public function testExternalEntitiesAreNotResolved(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE lexicon [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<lexicon><lexeme><grapheme>&xxe;</grapheme><alias>x</alias></lexeme></lexicon>';

        $graphemes = [];
        try {
            $graphemes = PlsDocument::fromString($xml)->graphemes();
        } catch (PlsException $e) {
            // Die Datei rundheraus abzulehnen ist ebenfalls ein richtiges Ergebnis.
            $graphemes = [];
        }

        self::assertStringNotContainsString(
            'root:',
            implode("\n", $graphemes),
            'Der Inhalt einer externen Entity darf nicht im Ergebnis auftauchen.'
        );
    }
}
