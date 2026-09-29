<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Text\DocxParser;
use Sonoquill\Text\SourceBlock;
use PHPUnit\Framework\TestCase;

/**
 * A fact script from Word: headings with their level, paragraphs as text.
 */
final class DocxParserTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /**
     * @return list<array{0:string,1:string,2:int}>
     */
    private static function blocks(string $body, string $styles = ''): array
    {
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document ' . self::W . ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"><w:body>'
            . $body . '</w:body></w:document>';

        return array_map(
            static fn (SourceBlock $b): array => [$b->type, $b->text, $b->level],
            DocxParser::fromXml($document, $styles)->blocks
        );
    }

    private static function styles(string $inner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><w:styles ' . self::W . '>' . $inner . '</w:styles>';
    }

    public function testReadsTheBundledSampleFile(): void
    {
        $blocks = array_map(
            static fn (SourceBlock $b): array => [$b->type, $b->text, $b->level],
            DocxParser::parse(dirname(__DIR__) . '/fixtures/docx/sample.docx')->blocks
        );

        self::assertSame([
            [SourceBlock::HEADING, 'Dokumenttitel', 0],
            [SourceBlock::HEADING, 'Kapitel 1: Der Mond', 1],
            [SourceBlock::PARAGRAPH, 'Einfacher Absatz mit Umlauten: Größe, Übung, ß.', 0],
            [SourceBlock::PARAGRAPH, 'Teil A, fett und Teil C.', 0],
            [SourceBlock::PARAGRAPH, 'Listenpunkt eins mit 1,5 mag', 0],
            [SourceBlock::PARAGRAPH, 'Listenpunkt zwei', 0],
            [SourceBlock::HEADING, 'Kapitel 2', 2],
            [SourceBlock::PARAGRAPH, 'Mit Tab nach Umbruch Link', 0],
            [SourceBlock::PARAGRAPH, 'Letzter Absatz – 18:30 Uhr, 2.300 km.', 0],
        ], $blocks);
    }

    public function testLocalisedHeadingStyleIsRecognisedByItsName(): void
    {
        $styles = self::styles(
            '<w:style w:type="paragraph" w:styleId="berschrift1"><w:name w:val="heading 1"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Gliederung"><w:name w:val="Gliederung"/><w:pPr><w:outlineLvl w:val="2"/></w:pPr></w:style>'
        );

        self::assertSame([
            [SourceBlock::HEADING, 'Der Mond', 1],
            [SourceBlock::HEADING, 'Nach Gliederungsebene', 3],
            [SourceBlock::PARAGRAPH, 'Text', 0],
        ], self::blocks(
            '<w:p><w:pPr><w:pStyle w:val="berschrift1"/></w:pPr><w:r><w:t>Der </w:t></w:r><w:r><w:t>Mond</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:pStyle w:val="Gliederung"/></w:pPr><w:r><w:t>Nach Gliederungsebene</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>Text</w:t></w:r></w:p>',
            $styles
        ));
    }

    public function testSkipsDeletedTextFieldInstructionsTablesAndDuplicates(): void
    {
        self::assertSame([
            [SourceBlock::PARAGRAPH, 'Seite 3 bleibt.', 0],
            [SourceBlock::PARAGRAPH, 'Im Steuerelement', 0],
        ], self::blocks(
            '<w:p><w:r><w:t xml:space="preserve">Seite </w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>3</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '<w:del><w:r><w:delText> gelöscht</w:delText></w:r></w:del>'
            . '<w:ins><w:r><w:t xml:space="preserve"> bleibt.</w:t></w:r></w:ins>'
            . '<w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing/></mc:Choice><mc:Fallback><w:pict><w:t>doppelt</w:t></w:pict></mc:Fallback></mc:AlternateContent></w:r></w:p>'
            . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Tabelle</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '<w:p/>'
            . '<w:sdt><w:sdtContent><w:p><w:r><w:t>Im Steuerelement</w:t></w:r></w:p></w:sdtContent></w:sdt>'
        ));
    }

    public function testBrokenXmlIsReportedNotSwallowed(): void
    {
        $this->expectException(\RuntimeException::class);
        DocxParser::fromXml('<w:document');
    }
}
