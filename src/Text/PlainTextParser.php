<?php
declare(strict_types=1);

namespace Sonoquill\Text;

/**
 * Fallback path for the source import: pasted text instead of DOCX.
 *
 * Paragraphs are separated at blank lines. Lines that begin with hash
 * characters are treated as headings — this way the outline can also be
 * provided when pasting.
 */
final class PlainTextParser
{
    public static function parse(string $text): SourceDocument
    {
        $normalized = Encoding::normalizeLineEndings(Encoding::toUtf8($text));
        $chunks = preg_split('/\n[ \t]*\n+/u', trim($normalized)) ?: [];

        $blocks = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            if (preg_match('/^(#{1,6})\s*(.+)$/su', $chunk, $m) === 1) {
                $blocks[] = new SourceBlock(SourceBlock::HEADING, trim($m[2]), strlen($m[1]));
                continue;
            }

            // Line breaks within a paragraph become spaces.
            $blocks[] = new SourceBlock(
                SourceBlock::PARAGRAPH,
                (string) preg_replace('/\s*\n\s*/u', ' ', $chunk)
            );
        }

        return new SourceDocument($blocks);
    }
}
