<?php
declare(strict_types=1);

namespace PodcastForge\Text;

/**
 * Turns HTML — the rendered content of a WordPress post — into a
 * fact script: headings h1–h6 stay headings; paragraphs, list items,
 * quotes and table rows become paragraphs. Images, scripts, forms and
 * embedded media are dropped; only text is read aloud.
 *
 * Deliberately free of WordPress functions so that it can be tested
 * without WordPress. The caller renders blocks and shortcodes beforehand.
 */
final class HtmlParser
{
    /** Elements whose content is never read aloud. */
    private const SKIP = ['script', 'style', 'noscript', 'iframe', 'object', 'embed', 'video', 'audio', 'img', 'svg', 'figure', 'form', 'button', 'input', 'select', 'textarea', 'nav', 'template'];

    /** Elements that form a paragraph of their own. */
    private const PARAGRAPHS = ['p', 'li', 'blockquote', 'pre', 'dd', 'dt', 'tr', 'caption', 'address'];

    public static function parse(string $html): SourceDocument
    {
        $html = trim(Encoding::toUtf8($html));
        if ($html === '') {
            return new SourceDocument([]);
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The meta prefix makes libxml read UTF-8 instead of Latin-1.
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        $blocks = [];
        if ($body !== null) {
            self::walk($body, $blocks);
        }

        return new SourceDocument($blocks);
    }

    /**
     * @param list<SourceBlock> $blocks
     */
    private static function walk(\DOMNode $node, array &$blocks): void
    {
        $inline = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $inline .= $child->textContent;
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::SKIP, true)) {
                continue;
            }

            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                self::flush($inline, $blocks);
                $text = self::text($child);
                if ($text !== '') {
                    $blocks[] = new SourceBlock(SourceBlock::HEADING, $text, (int) $m[1]);
                }
                continue;
            }

            if (in_array($tag, self::PARAGRAPHS, true)) {
                self::flush($inline, $blocks);
                // A list item containing a nested list: its own text is one
                // paragraph, and the sub-items become paragraphs of their own.
                if ($tag === 'li' && self::hasBlockChildren($child)) {
                    self::walk($child, $blocks);
                    continue;
                }
                $text = $tag === 'tr' ? self::rowText($child) : self::text($child);
                if ($text !== '') {
                    $blocks[] = new SourceBlock(SourceBlock::PARAGRAPH, $text);
                }
                continue;
            }

            if ($tag === 'br') {
                $inline .= ' ';
                continue;
            }

            if (self::isInline($tag)) {
                $inline .= self::text($child) . ' ';
                continue;
            }

            // Container (div, section, article, ul, ol, table, …): descend into it.
            self::flush($inline, $blocks);
            self::walk($child, $blocks);
        }

        self::flush($inline, $blocks);
    }

    /**
     * @param list<SourceBlock> $blocks
     */
    private static function flush(string &$inline, array &$blocks): void
    {
        $text = self::clean($inline);
        $inline = '';
        if ($text !== '') {
            $blocks[] = new SourceBlock(SourceBlock::PARAGRAPH, $text);
        }
    }

    private static function text(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= $child->textContent;
            } elseif ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::SKIP, true)) {
                    continue;
                }
                $out .= $tag === 'br' ? ' ' : self::text($child) . (self::isInline($tag) ? '' : ' ');
            }
        }

        return self::clean($out);
    }

    private static function rowText(\DOMElement $row): string
    {
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                $text = self::text($cell);
                if ($text !== '') {
                    $cells[] = $text;
                }
            }
        }

        return implode(', ', $cells);
    }

    private static function hasBlockChildren(\DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol', 'p', 'div'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isInline(string $tag): bool
    {
        return in_array($tag, ['a', 'span', 'strong', 'b', 'em', 'i', 'u', 'small', 'sub', 'sup', 'mark', 'abbr', 'cite', 'code', 'q', 'time', 'del', 'ins', 's', 'kbd', 'var', 'data'], true);
    }

    private static function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
