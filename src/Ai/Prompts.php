<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Text\Encoding;

/**
 * The core prompts, per language.
 *
 * Which version applies, in this order:
 *
 * 1. the one edited in the backend (option `aaspf_prompts`, per language),
 * 2. a file `<name>.md` in the configured prompt directory — for anyone who
 *    keeps their prompts under version control alongside the website,
 * 3. the bundled file `prompts/<language>/<name>.md`.
 *
 * The placeholders {podcast}, {host}, {editor} and {sign_off} are filled in
 * on retrieval (Options::fill), in each of the three versions.
 */
final class Prompts
{
    public const OPTION = 'aaspf_prompts';

    /** Name => short description for the backend. */
    public const NAMES = ['script', 'metadata', 'factcheck', 'dictionary'];

    public const LANGUAGES = ['de', 'en'];

    /**
     * The prompt as it is sent to the model.
     *
     * @throws AnthropicException if no version exists.
     */
    public static function get(string $name): string
    {
        $text = trim(self::raw($name)['text']);
        if ($text === '') {
            /* translators: %s: name of the prompt */
            throw new AnthropicException(sprintf(__('The prompt "%s" is missing or empty.', 'sonoquill'), $name));
        }

        return Options::fill($text);
    }

    /**
     * The applicable version without filled-in placeholders, along with its source.
     *
     * @return array{text:string,source:string,path:string}
     */
    public static function raw(string $name, ?string $language = null): array
    {
        self::assertName($name);
        $language = $language ?? Options::language();

        $override = self::override($name, $language);
        if ($override !== '') {
            return ['text' => $override, 'source' => 'override', 'path' => ''];
        }

        $custom = trim(Options::get('prompt_dir'));
        if ($custom !== '') {
            $path = untrailingslashit($custom) . '/' . $name . '.md';
            if (is_readable($path)) {
                return ['text' => Encoding::readFile($path), 'source' => 'directory', 'path' => $path];
            }
        }

        $path = self::builtinPath($name, $language);

        return ['text' => is_readable($path) ? Encoding::readFile($path) : '', 'source' => 'builtin', 'path' => $path];
    }

    public static function builtin(string $name, ?string $language = null): string
    {
        $path = self::builtinPath($name, $language ?? Options::language());

        return is_readable($path) ? Encoding::readFile($path) : '';
    }

    public static function builtinPath(string $name, string $language): string
    {
        self::assertName($name);
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'en';

        return untrailingslashit(AASPF_PLUGIN_DIR) . '/prompts/' . $language . '/' . $name . '.md';
    }

    public static function override(string $name, string $language): string
    {
        $all = get_option(self::OPTION, []);

        return is_array($all) ? trim((string) ($all[$language][$name] ?? '')) : '';
    }

    /**
     * Saves an edited version; empty or identical to the default means: revert to the default.
     */
    public static function saveOverride(string $name, string $language, string $text): void
    {
        self::assertName($name);
        $all = get_option(self::OPTION, []);
        $all = is_array($all) ? $all : [];

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '' || $text === trim(self::builtin($name, $language))) {
            unset($all[$language][$name]);
        } else {
            $all[$language][$name] = $text;
        }

        update_option(self::OPTION, $all, false);
    }

    private static function assertName(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            /* translators: %s: requested prompt name */
            throw new \InvalidArgumentException(sprintf(__('Unknown prompt "%s".', 'sonoquill'), $name));
        }
    }
}
