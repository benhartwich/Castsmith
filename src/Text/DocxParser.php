<?php
declare(strict_types=1);

namespace PodcastForge\Text;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * Reads the fact script from a DOCX file.
 *
 * A DOCX file is a ZIP archive; the text is in word/document.xml. Only two
 * things are needed from it: headings (with their level) and paragraphs.
 * That is little enough to read directly instead of shipping a document
 * library. ZipArchive is used when available, otherwise the PclZip copy that
 * WordPress itself ships.
 *
 * A paragraph is a heading when its style is a heading style — recognised by
 * the style ID ("Heading2", "Title"), by the style name in styles.xml
 * ("heading 2", which also covers localised IDs such as "berschrift2") or by
 * an outline level. Tables, text boxes, deleted text and field instructions
 * are skipped; empty paragraphs are dropped.
 */
final class DocxParser
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Largest document.xml that is parsed; guards against zip bombs. */
    private const MAX_XML_BYTES = 50 * 1024 * 1024;

    /**
     * @throws \RuntimeException
     */
    public static function parse(string $path): SourceDocument
    {
        if (!is_readable($path)) {
            /* translators: %s: file path of the DOCX file */
            throw new \RuntimeException(sprintf(__('DOCX file could not be read: %s', 'podcast-forge'), $path));
        }

        $document = self::part($path, 'word/document.xml');
        if ($document === null) {
            throw new \RuntimeException(__('The DOCX file could not be opened: ', 'podcast-forge') . __('it contains no word/document.xml.', 'podcast-forge'));
        }

        return self::fromXml($document, self::part($path, 'word/styles.xml') ?? '');
    }

    /**
     * @throws \RuntimeException
     */
    public static function fromXml(string $documentXml, string $stylesXml = ''): SourceDocument
    {
        $dom = self::load($documentXml);
        if ($dom === null) {
            throw new \RuntimeException(__('The DOCX file could not be opened: ', 'podcast-forge') . __('word/document.xml is not valid XML.', 'podcast-forge'));
        }

        $levels = self::styleLevels($stylesXml);
        $body = $dom->getElementsByTagNameNS(self::NS, 'body')->item(0);
        if (!$body instanceof \DOMElement) {
            return new SourceDocument([]);
        }

        $blocks = [];
        foreach (self::paragraphs($body) as $paragraph) {
            $text = self::flatten(self::text($paragraph));
            if ($text === '') {
                continue;
            }

            $level = self::headingLevel($paragraph, $levels);
            $blocks[] = $level === null
                ? new SourceBlock(SourceBlock::PARAGRAPH, $text)
                : new SourceBlock(SourceBlock::HEADING, $text, $level);
        }

        return new SourceDocument($blocks);
    }

    /**
     * The paragraphs of the body in document order: direct children and
     * those inside content controls, but not those in tables.
     *
     * @return list<\DOMElement>
     */
    private static function paragraphs(\DOMElement $container): array
    {
        $out = [];
        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->namespaceURI !== self::NS) {
                continue;
            }
            if ($child->localName === 'p') {
                $out[] = $child;
            } elseif ($child->localName === 'sdt' || $child->localName === 'sdtContent') {
                $out = array_merge($out, self::paragraphs($child));
            }
        }

        return $out;
    }

    private static function text(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if ($child->namespaceURI === self::NS) {
                switch ($child->localName) {
                    case 't':
                        $out .= $child->textContent;
                        continue 2;
                    case 'tab':
                    case 'br':
                    case 'cr':
                        $out .= ' ';
                        continue 2;
                    case 'del':
                    case 'delText':
                    case 'instrText':
                    case 'pPr':
                    case 'rPr':
                    case 'txbxContent':
                        continue 2;
                }
            } elseif ($child->localName === 'Fallback') {
                // mc:AlternateContent carries the same content twice.
                continue;
            }

            $out .= self::text($child);
        }

        return $out;
    }

    /**
     * @param array<string,int> $levels heading level per style ID
     */
    private static function headingLevel(\DOMElement $paragraph, array $levels): ?int
    {
        $properties = self::child($paragraph, 'pPr');
        if ($properties === null) {
            return null;
        }

        $style = self::child($properties, 'pStyle');
        if ($style !== null) {
            $id = $style->getAttributeNS(self::NS, 'val');
            if ($id === 'Title') {
                return 0;
            }
            if (preg_match('/Heading(\d)/', $id, $m) === 1) {
                return (int) $m[1];
            }
            if (isset($levels[$id])) {
                return $levels[$id];
            }
        }

        return self::outlineLevel($properties);
    }

    /**
     * Heading level per style ID, from styles.xml.
     *
     * @return array<string,int>
     */
    private static function styleLevels(string $stylesXml): array
    {
        if ($stylesXml === '') {
            return [];
        }

        $dom = self::load($stylesXml);
        if ($dom === null) {
            return [];
        }

        $levels = [];
        foreach ($dom->getElementsByTagNameNS(self::NS, 'style') as $style) {
            if (!$style instanceof \DOMElement || $style->getAttributeNS(self::NS, 'type') !== 'paragraph') {
                continue;
            }

            $id = $style->getAttributeNS(self::NS, 'styleId');
            $nameNode = self::child($style, 'name');
            $name = $nameNode !== null ? strtolower($nameNode->getAttributeNS(self::NS, 'val')) : '';

            if ($name === 'title') {
                $levels[$id] = 0;
            } elseif (preg_match('/^heading (\d)$/', $name, $m) === 1) {
                $levels[$id] = (int) $m[1];
            } else {
                $properties = self::child($style, 'pPr');
                $level = $properties !== null ? self::outlineLevel($properties) : null;
                if ($level !== null) {
                    $levels[$id] = $level;
                }
            }
        }

        return $levels;
    }

    /**
     * w:outlineLvl counts from 0; 9 means body text.
     */
    private static function outlineLevel(\DOMElement $properties): ?int
    {
        $outline = self::child($properties, 'outlineLvl');
        if ($outline === null) {
            return null;
        }

        $value = (int) $outline->getAttributeNS(self::NS, 'val');

        return $value >= 0 && $value < 9 ? $value + 1 : null;
    }

    private static function child(\DOMElement $parent, string $name): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === self::NS && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    private static function load(string $xml): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // No network access and no entity expansion from outside the file.
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $dom : null;
    }

    /**
     * One file from the archive, or null if it is missing.
     */
    private static function part(string $path, string $name): ?string
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                return null;
            }
            $stat = $zip->statName($name);
            $data = is_array($stat) && $stat['size'] <= self::MAX_XML_BYTES ? $zip->getFromName($name) : false;
            $zip->close();

            return is_string($data) ? $data : null;
        }

        if (!class_exists('PclZip')) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $archive = new \PclZip($path);
        $entries = $archive->extract(PCLZIP_OPT_BY_NAME, $name, PCLZIP_OPT_EXTRACT_AS_STRING);
        if (!is_array($entries) || !isset($entries[0]['content']) || ($entries[0]['size'] ?? 0) > self::MAX_XML_BYTES) {
            return null;
        }

        return (string) $entries[0]['content'];
    }

    private static function flatten(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Encoding::toUtf8($value)));
    }
}
