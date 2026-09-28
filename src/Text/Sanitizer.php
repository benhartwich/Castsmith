<?php
declare(strict_types=1);

namespace PodcastForge\Text;

/**
 * Sanitises the long texts that are typed or pasted in the admin: the fact
 * script, the spoken script and the prompts.
 *
 * These texts are never rendered as HTML — they go to the speech synthesis
 * or the language model and are escaped wherever they are displayed. They
 * are still cleaned on input, with an allowlist: markup is removed except
 * the one tag the speech synthesis understands, <break time="1.5s" />,
 * which is rebuilt in a fixed form (any other attribute is dropped).
 *
 * The core text sanitisers are not used on purpose: sanitize_textarea_field()
 * and wp_kses() treat a lone "<" as the start of a tag and cut the text after
 * it ("Wert <5 % oder 1<2" becomes "Wert "; "brighter than < 3 mag" loses the
 * rest of the sentence), and wp_kses() turns "&" into "&amp;", which the
 * speech synthesis would read out. Here, only what is shaped like a tag is
 * removed.
 */
final class Sanitizer
{
    /** The pause tag of the speech synthesis. */
    private const BREAK_TAG = '/<break\s+time\s*=\s*(["\']?)(\d{1,2}(?:\.\d{1,3})?)s\1[^<>]*>/i';

    /** Anything shaped like an HTML or XML tag, comment or declaration. */
    private const TAG = '/<!--.*?-->|<[!?\/]?[a-zA-Z][^<>]*>/s';

    /** Elements whose content is not text. */
    private const HIDDEN = '/<(script|style|iframe|object|template)\b[^>]*>.*?<\/\1\s*>/is';

    /** Private-use characters mark the kept pause tags while the rest is cleaned. */
    private const MARK_OPEN  = "\u{E000}";
    private const MARK_CLOSE = "\u{E001}";

    /**
     * Plain text (fact script): no markup at all.
     */
    public static function plain(string $text): string
    {
        return self::clean($text, false);
    }

    /**
     * Spoken script and prompts: plain text plus pause tags.
     */
    public static function script(string $text): string
    {
        return self::clean($text, true);
    }

    private static function clean(string $text, bool $keepBreaks): string
    {
        $text = self::validUtf8($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Control and format characters, except line breaks and tabs. This
        // also removes any private-use characters, so the input cannot
        // forge the markers used below.
        $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', $text);
        $text = (string) preg_replace(self::HIDDEN, '', $text);

        $breaks = [];
        if ($keepBreaks) {
            $text = (string) preg_replace_callback(
                self::BREAK_TAG,
                static function (array $m) use (&$breaks): string {
                    $breaks[] = '<break time="' . $m[2] . 's" />';

                    return self::MARK_OPEN . (count($breaks) - 1) . self::MARK_CLOSE;
                },
                $text
            );
        }

        $text = (string) preg_replace(self::TAG, '', $text);

        if ($breaks !== []) {
            $text = (string) preg_replace_callback(
                '/' . self::MARK_OPEN . '(\d+)' . self::MARK_CLOSE . '/u',
                static fn (array $m): string => $breaks[(int) $m[1]] ?? '',
                $text
            );
        }

        return trim($text);
    }

    private static function validUtf8(string $text): string
    {
        if (function_exists('wp_check_invalid_utf8')) {
            return wp_check_invalid_utf8($text, true);
        }

        return mb_check_encoding($text, 'UTF-8') ? $text : (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}
