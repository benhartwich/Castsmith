<?php
declare(strict_types=1);

namespace Sonoquill\Text;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * Converts files that have been read in to UTF-8.
 *
 * There is a concrete reason for this: the system prompt arrived as
 * Windows-1252 because it was created on Windows. Without detection, a broken
 * em-dash byte ends up in the prompt and from there in the request to the model.
 *
 * The order of the checks matters. UTF-8 comes first, because valid UTF-8
 * could also be decoded as Windows-1252 — just incorrectly.
 */
final class Encoding
{
    public static function toUtf8(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        // Strip the byte order mark and derive the encoding from it.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        foreach (["\xFF\xFE" => 'UTF-16LE', "\xFE\xFF" => 'UTF-16BE'] as $bom => $encoding) {
            if (str_starts_with($raw, $bom)) {
                return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', $encoding);
            }
        }

        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // Windows-1252 is the most likely alternative in this environment
        // and also covers Latin-1.
        return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    /**
     * Reads a file and returns its contents as UTF-8.
     *
     * @throws \RuntimeException
     */
    public static function readFile(string $path): string
    {
        if (!is_readable($path)) {
            /* translators: %s: path of the file */
            throw new \RuntimeException(sprintf(__('File is not readable: %s', 'sonoquill'), $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            /* translators: %s: path of the file */
            throw new \RuntimeException(sprintf(__('File could not be read: %s', 'sonoquill'), $path));
        }

        return self::normalizeLineEndings(self::toUtf8($raw));
    }

    public static function normalizeLineEndings(string $text): string
    {
        return (string) preg_replace("/\r\n?/", "\n", $text);
    }
}
