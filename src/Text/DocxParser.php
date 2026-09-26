<?php
declare(strict_types=1);

namespace PodcastForge\Text;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

/**
 * Reads the fact script from a DOCX file.
 *
 * PHPWord instead of pandoc: pandoc would be one more system dependency that
 * most hosting does not have. PHPWord represents headings as a Title with a
 * level and paragraphs as a TextRun — exactly the separation the fact script
 * needs.
 */
final class DocxParser
{
    /**
     * @throws \RuntimeException
     */
    public static function parse(string $path): SourceDocument
    {
        if (!is_readable($path)) {
            /* translators: %s: file path of the DOCX file */
            throw new \RuntimeException(sprintf(__('DOCX file could not be read: %s', 'podcast-forge'), $path));
        }

        try {
            $document = IOFactory::load($path, 'Word2007');
        } catch (\Throwable $e) {
            throw new \RuntimeException(__('The DOCX file could not be opened: ', 'podcast-forge') . $e->getMessage(), 0, $e);
        }

        $blocks = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $block = self::toBlock($element);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return new SourceDocument($blocks);
    }

    private static function toBlock(object $element): ?SourceBlock
    {
        if ($element instanceof Title) {
            $text = self::flatten($element->getText());

            return $text === '' ? null : new SourceBlock(SourceBlock::HEADING, $text, (int) $element->getDepth());
        }

        if ($element instanceof TextRun || $element instanceof ListItem) {
            $text = self::collect($element);

            return $text === '' ? null : new SourceBlock(SourceBlock::PARAGRAPH, $text);
        }

        if ($element instanceof Text) {
            $text = self::flatten($element->getText());

            return $text === '' ? null : new SourceBlock(SourceBlock::PARAGRAPH, $text);
        }

        return null;
    }

    private static function collect(object $element): string
    {
        if ($element instanceof ListItem) {
            return self::flatten($element->getTextObject()->getText());
        }

        $parts = [];
        foreach ($element->getElements() as $child) {
            if (method_exists($child, 'getText')) {
                $parts[] = self::flatten($child->getText());
            }
        }

        return self::flatten(implode('', $parts));
    }

    /**
     * @param mixed $value
     */
    private static function flatten($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', Encoding::toUtf8($value)));
    }
}
