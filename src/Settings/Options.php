<?php
declare(strict_types=1);

namespace PodcastForge\Settings;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use PodcastForge\Support\Crypto;
use PodcastForge\Support\CryptoException;
use PodcastForge\Support\KeyStore;

/**
 * Single storage location for the plugin settings: one option, one array.
 *
 * The three credentials are stored in it encrypted, everything else in plain text.
 * The option is not autoloaded — it is only needed in the admin backend.
 */
final class Options
{
    public const OPTION = 'aaspf_settings';

    /** Fields that are stored encrypted. */
    public const SECRET_FIELDS = [
        'elevenlabs_api_key',
        'anthropic_api_key',
        'auphonic_api_key',
    ];

    /**
     * @return array<string,string|bool>
     */
    public static function defaults(): array
    {
        // The default texts follow the site language at the time they are first
        // stored; after that the stored text applies.
        $german = str_starts_with(function_exists('get_locale') ? (string) get_locale() : 'en_US', 'de');

        $defaults = [
            // The podcast. Empty means: derived from Podlove or from the website.
            'podcast_name'         => '',
            'podcast_host'         => '',
            'podcast_editor'       => '',
            'podcast_sign_off'     => '',
            'file_prefix'          => '',
            'podcast_language'     => '',
            'elevenlabs_api_key'   => '',
            'elevenlabs_voice_id'  => '',
            'elevenlabs_model_id'  => 'eleven_multilingual_v2',
            'elevenlabs_dictionary_id'         => '',
            'elevenlabs_dictionary_version_id' => '',
            'anthropic_api_key'    => '',
            'anthropic_model'      => 'claude-opus-5-5',
            'anthropic_model_research' => 'claude-sonnet-5',
            'anthropic_batch'      => true,
            'music_enabled'        => false,
            'anthropic_workspace_id' => '',
            'auphonic_api_key'     => '',
            'auphonic_preset'      => '',
            'auphonic_webhook_token' => '',
            'ai_disclosure_text'   => $german
                ? 'Zur Entstehung dieser Folge: Der Text wurde mit Unterstützung eines Sprachmodells aufbereitet und wird von einer synthetischen Nachbildung der Stimme von {host} gelesen. Zahlen und Inhalte werden maschinell gegen die Vorlage geprüft, und online geht nur, was ein Mensch freigegeben hat.'
                : 'About this episode: the text was prepared with the help of a language model and is read by a synthetic replica of the voice of {host}. Numbers and content are checked automatically against the source, and nothing goes online that a person has not approved.',
            'ai_disclosure_audio_text' => $german
                ? 'Noch ein Hinweis in eigener Sache: Diese Folge ist mit Unterstützung künstlicher Intelligenz entstanden, und die Stimme, die ihr hört, ist eine synthetische Nachbildung meiner eigenen. Bevor eine Folge online geht, prüfe ich Text und Ton selbst.'
                : 'One more note: this episode was created with the help of artificial intelligence, and the voice you are hearing is a synthetic replica of my own. Before an episode goes online, I check the text and the audio myself.',
            'notify_email'         => '',
            'prompt_dir'           => '',
            'dictionary_file'      => '',
            'storage_dir'          => '',
            'voice_stability'      => '0.5',
            'voice_similarity'     => '0.75',
            'voice_style'          => '0',
            'voice_speed'          => '1.0',
            'voice_speaker_boost'  => '1',
            'tts_output_format'    => 'mp3_44100_192',
            'chapter_pause_ms'     => '1500',
            'paragraph_pause_ms'   => '450',
            'montage_mode'         => 'auto',
            'ffmpeg_path'          => 'ffmpeg',
            'ffprobe_path'         => 'ffprobe',
            'delete_data_on_uninstall' => false,
        ];

        if (!function_exists('apply_filters')) {
            return $defaults;
        }

        /**
         * Filter: default settings, extended by the fields of add-ons. Add-ons
         * store their values in the same option so that an export contains everything.
         */
        return array_merge($defaults, (array) apply_filters('podcast_forge_option_defaults', []));
    }

    /**
     * Raw stored data, including the secrets that are still encrypted.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    public static function get(string $key): string
    {
        if (in_array($key, self::SECRET_FIELDS, true)) {
            /* translators: %s: name of the settings field */
            throw new \LogicException(sprintf(__('"%s" is a secret and must be read via secret().', 'podcast-forge'), $key));
        }

        $all = self::all();

        return isset($all[$key]) ? (string) $all[$key] : '';
    }

    /**
     * The name of the podcast: the configured one, otherwise the title from
     * Podlove, otherwise the name of the website.
     */
    public static function podcastName(): string
    {
        $name = trim(self::get('podcast_name'));
        if ($name !== '') {
            return $name;
        }

        $podlove = get_option('podlove_podcast');
        if (is_array($podlove) && trim((string) ($podlove['title'] ?? '')) !== '') {
            return trim((string) $podlove['title']);
        }

        return (string) get_bloginfo('name');
    }

    /**
     * Language of the podcast: "de" or "en". Determines the prompts and the
     * number comparison. Empty: taken from the language of the website.
     */
    public static function language(): string
    {
        $configured = strtolower(trim(self::get('podcast_language')));
        if (in_array($configured, ['de', 'en'], true)) {
            return $configured;
        }

        $locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';

        return str_starts_with($locale, 'de') ? 'de' : 'en';
    }

    /**
     * Prefix for file names and slugs ("…Oktober2026"): the configured one,
     * otherwise derived from the name of the podcast.
     */
    public static function filePrefix(): string
    {
        $prefix = \PodcastForge\Podlove\SlugBuilder::asciify(self::get('file_prefix'));
        if ($prefix === '') {
            $prefix = \PodcastForge\Podlove\SlugBuilder::asciify(self::podcastName());
        }

        return $prefix !== '' ? mb_substr($prefix, 0, 40) : 'Podcast';
    }

    /**
     * Inserts the podcast details into a text: {podcast}, {host},
     * {editor}, {sign_off}. If the host is missing, "our host" is used
     * instead (fits after "by") — the sentence stays correct, just more
     * general.
     */
    public static function fill(string $template): string
    {
        $host = trim(self::get('podcast_host'));

        return strtr($template, [
            '{podcast}'  => self::podcastName(),
            '{host}'     => $host !== '' ? $host : __('our host', 'podcast-forge'),
            // Empty details become an em dash: "Editor: —." reads more clearly
            // than "Editor: ." — the prompts explain what applies in that case.
            '{editor}'   => trim(self::get('podcast_editor')) !== '' ? trim(self::get('podcast_editor')) : '—',
            '{sign_off}' => trim(self::get('podcast_sign_off')) !== '' ? trim(self::get('podcast_sign_off')) : '—',
        ]);
    }

    public static function flag(string $key): bool
    {
        $all = self::all();

        return !empty($all[$key]);
    }

    public static function hasSecret(string $field): bool
    {
        $all = self::all();

        return isset($all[$field]) && is_string($all[$field]) && $all[$field] !== '';
    }

    /**
     * Decrypts a secret. An empty string means "not stored".
     *
     * @throws CryptoException if the key is missing, does not match, or the value is corrupted.
     */
    public static function secret(string $field): string
    {
        if (!in_array($field, self::SECRET_FIELDS, true)) {
            /* translators: %s: name of the settings field */
            throw new \LogicException(sprintf(__('"%s" is not a secret.', 'podcast-forge'), $field));
        }

        $all = self::all();
        $value = isset($all[$field]) ? (string) $all[$field] : '';

        if ($value === '') {
            return '';
        }

        if (!Crypto::looksEncrypted($value)) {
            throw new CryptoException(sprintf(
                /* translators: %s: name of the credential settings field */
                __('The field "%s" is stored unencrypted in the database. Please enter it again in the settings.', 'podcast-forge'),
                $field
            ));
        }

        return KeyStore::crypto()->decrypt($value);
    }

    /**
     * Writes the raw data back. Secrets must already be encrypted.
     *
     * @param array<string,mixed> $values
     */
    public static function save(array $values): void
    {
        // The settings page sanitizer is meant for form input. In admin
        // requests (including background jobs via admin-ajax) it would
        // otherwise hook into this write operation.
        $filter = 'sanitize_option_' . self::OPTION;
        // Copy: remove_all_filters empties the same WP_Hook object.
        $callbacks = isset($GLOBALS['wp_filter'][$filter]) ? clone $GLOBALS['wp_filter'][$filter] : null;
        remove_all_filters($filter);

        try {
            update_option(self::OPTION, $values, false);
        } finally {
            if ($callbacks !== null) {
                $GLOBALS['wp_filter'][$filter] = $callbacks;
            }
        }
    }

    /**
     * Registers the option with autoload=no so that the credentials are not
     * loaded on every frontend request.
     */
    public static function ensureExists(): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
    }

    /**
     * Display form of a secret: only the last four characters.
     */
    public static function maskSecret(string $plaintext): string
    {
        $length = strlen($plaintext);
        if ($length === 0) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', 8) . substr($plaintext, -4);
    }
}
