<?php
declare(strict_types=1);

namespace Castsmith\Tests\Unit;

use Castsmith\Text\HtmlParser;
use Castsmith\Text\SourceBlock;
use PHPUnit\Framework\TestCase;

/**
 * Ein WordPress-Beitrag als Faktenskript: Gliederung bleibt, nur Text wird vorgelesen.
 */
final class HtmlParserTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string,2:int}>
     */
    private static function blocks(string $html): array
    {
        return array_map(
            static fn (SourceBlock $b): array => [$b->type, $b->text, $b->level],
            HtmlParser::parse($html)->blocks
        );
    }

    public function testHeadingsAndParagraphsFromBlockEditorMarkup(): void
    {
        $html = "<!-- wp:heading --><h2 class=\"wp-block-heading\">Saturn im Oktober</h2><!-- /wp:heading -->\n"
            . "<!-- wp:paragraph --><p>Am <strong>4. Oktober</strong> steht Saturn in Opposition.</p><!-- /wp:paragraph -->\n"
            . '<p>Zweiter&nbsp;Absatz mit &auml;, &#8211; und &amp;.</p>';

        self::assertSame([
            [SourceBlock::HEADING, 'Saturn im Oktober', 2],
            [SourceBlock::PARAGRAPH, 'Am 4. Oktober steht Saturn in Opposition.', 0],
            [SourceBlock::PARAGRAPH, 'Zweiter Absatz mit ä, – und &.', 0],
        ], self::blocks($html));
    }

    public function testMediaScriptsAndFiguresAreDropped(): void
    {
        $html = '<p>Vorher.</p><figure class="wp-block-image"><img src="x.jpg" alt="Bild"><figcaption>Bildunterschrift</figcaption></figure>'
            . '<script>alert(1)</script><iframe src="https://example.com"></iframe><p>Nachher.</p>';

        self::assertSame(['Vorher.', 'Nachher.'], array_column(self::blocks($html), 1));
    }

    public function testListsBecomeOneParagraphPerItemIncludingNested(): void
    {
        $html = '<ul><li>Erster Punkt</li><li>Zweiter Punkt<ul><li>Unterpunkt</li></ul></li></ul>';

        self::assertSame(['Erster Punkt', 'Zweiter Punkt', 'Unterpunkt'], array_column(self::blocks($html), 1));
    }

    public function testTableRowsAreReadAsCommaSeparatedLines(): void
    {
        $html = '<table><tr><th>Objekt</th><th>Helligkeit</th></tr><tr><td>Saturn</td><td>0,4 mag</td></tr></table>';

        self::assertSame(['Objekt, Helligkeit', 'Saturn, 0,4 mag'], array_column(self::blocks($html), 1));
    }

    public function testLooseTextAndLineBreaksBecomeParagraphs(): void
    {
        $html = "Loser Text am Anfang<br>mit Umbruch.<div>In einem Div.</div>";

        self::assertSame(['Loser Text am Anfang mit Umbruch.', 'In einem Div.'], array_column(self::blocks($html), 1));
    }

    public function testEmptyInputGivesEmptyDocument(): void
    {
        self::assertTrue(HtmlParser::parse('   ')->isEmpty());
        self::assertTrue(HtmlParser::parse('<p> </p><img src="a.jpg">')->isEmpty());
    }
}
