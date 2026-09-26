<?php
declare(strict_types=1);

namespace PodcastForge\Voice;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use PodcastForge\Settings\Options;
use PodcastForge\Support\CryptoException;
use PodcastForge\Support\PlsDocument;
use PodcastForge\Text\Encoding;

/**
 * The permanent path: a rule that takes effect in every future episode.
 *
 * The order is deliberate: the rule is first written to the local `.pls`
 * and then sent to ElevenLabs. Only this way does the file remain the
 * source of truth and keep the pronunciation decisions in the version
 * history. If it does not exist yet, it is downloaded from the bound version
 * the first time and thereby created.
 *
 * A second dictionary is never created. `add-rules` returns a new
 * `version_id`, which is immediately registered as the bound version —
 * a rule that nobody binds has no effect anywhere.
 */
final class DictionaryWriter
{
    /** File name in the storage directory when no custom path is configured. */
    public const FILE = 'pronunciation.pls';

    private const BASE = 'https://api.elevenlabs.io/v1/pronunciation-dictionaries/';

    /**
     * The local dictionary file: the configured path, otherwise in the storage directory.
     * Not in the plugin directory — it would be lost there on the next update.
     * If it is missing, the bound version is loaded from ElevenLabs.
     */
    public static function path(): string
    {
        $configured = trim(Options::get('dictionary_file'));
        if ($configured !== '') {
            return $configured;
        }

        return \PodcastForge\Storage\EpisodeStorage::baseDir() . '/' . self::FILE;
    }

    /**
     * Which rule type actually takes effect on the configured model.
     *
     * Phonetic transcription is more precise, but is silently discarded
     * outside of Eleven v3 — measured: with v3 a forced phonetic transcription
     * lengthens the sentence by a full second, with Multilingual v2 nothing happens.
     * The rule type therefore depends on the model and is not hard-coded.
     */
    public static function preferredRuleType(): string
    {
        return in_array(Options::get('elevenlabs_model_id'), self::PHONEME_MODELS, true)
            ? 'phoneme'
            : 'alias';
    }

    /** Models that evaluate phonetic transcription beyond English. */
    private const PHONEME_MODELS = ['eleven_v3', 'eleven_v3_conversational'];

    /**
     * Creates a rule or replaces an existing one.
     *
     * Both forms are accepted — phonetic transcription and German
     * respelling —, and the one that takes effect on the configured model is stored.
     * This way a later model change has no consequences for the caller.
     *
     * @return array{version_id:string,rules:int,datei:string,ersetzt:bool,art:string}
     *
     * @throws \RuntimeException
     */
    public static function addRule(string $grapheme, string $ipa, string $alias = ''): array
    {
        $grapheme = trim($grapheme);
        $ipa = trim($ipa);
        $alias = trim($alias);

        if ($grapheme === '') {
            throw new \RuntimeException(__('The term the rule should apply to is missing.', 'podcast-forge'));
        }

        $art = self::preferredRuleType();
        $wert = $art === 'phoneme' ? $ipa : $alias;

        // If the preferred form is missing, the other one is used instead of
        // silently creating nothing.
        if ($wert === '') {
            $wert = $art === 'phoneme' ? $alias : $ipa;
            $art = $art === 'phoneme' ? 'alias' : 'phoneme';
        }

        if ($wert === '') {
            throw new \RuntimeException(__('Both the phonetic transcription and the respelling are missing.', 'podcast-forge'));
        }

        // Filter out characters that the voice demonstrably renders incorrectly.
        if ($art === 'phoneme') {
            $wert = PhonemeGuard::bereinige($wert);
        }

        $dictionaryId = Options::get('elevenlabs_dictionary_id');
        if ($dictionaryId === '') {
            throw new \RuntimeException(__('No dictionary is configured.', 'podcast-forge'));
        }

        $key = self::apiKey();

        // 1. Update the local file before anything is sent.
        //    An existing rule is replaced, not duplicated.
        $local = self::currentDocumentSource($key, $dictionaryId);
        $hadRule = self::hasGrapheme($local, $grapheme);
        $nebenform = $art === 'phoneme' ? $alias : PhonemeGuard::bereinige($ipa);
        $updated = self::appendLexeme(self::removeLexeme($local, $grapheme), $grapheme, $wert, $art, $nebenform);
        self::writeLocal($updated);

        // 2. Remove it from the service first as well. remove-rules creates
        //    its own version; that one does not count, though, because the
        //    version with the new rule is bound right afterwards.
        if ($hadRule) {
            self::removeRule($key, $dictionaryId, $grapheme);
        }

        // 3. Register the rule. The service responds with a new version.
        $regel = ['string_to_replace' => $grapheme, 'type' => $art];
        if ($art === 'phoneme') {
            $regel['phoneme'] = $wert;
            $regel['alphabet'] = 'ipa';
        } else {
            $regel['alias'] = $wert;
        }
        $rules = [$regel];

        $response = wp_remote_post(self::BASE . rawurlencode($dictionaryId) . '/add-rules', [
            'timeout' => 30,
            'headers' => [
                'xi-api-key'   => $key,
                'content-type' => 'application/json',
            ],
            'body' => (string) wp_json_encode(['rules' => $rules]),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'podcast-forge') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($body) || !isset($body['version_id'])) {
            $detail = is_array($body) && isset($body['detail'])
                ? (is_array($body['detail']) ? (string) ($body['detail']['message'] ?? '') : (string) $body['detail'])
                : '';

            /* translators: 1: HTTP status code, 2: error detail from ElevenLabs */
            throw new \RuntimeException(sprintf(__('ElevenLabs responds with HTTP %1$d. %2$s', 'podcast-forge'), $code, $detail));
        }

        // 4. Bind the new version immediately. Otherwise the rule has no effect anywhere.
        $all = Options::all();
        $all['elevenlabs_dictionary_version_id'] = (string) $body['version_id'];
        Options::save($all);

        return [
            'version_id' => (string) $body['version_id'],
            'rules'      => (int) ($body['version_rules_num'] ?? 0),
            'datei'      => self::path(),
            'ersetzt'    => $hadRule,
            'art'        => $art,
        ];
    }

    /**
     * Builds a complete PLS file from a list of rules.
     *
     * A pure function, without WordPress and without network access — so it
     * can be checked before anything is sent.
     *
     * @param list<array{grapheme:string,type:string,value:string,alphabet?:string}> $rules
     */
    public static function buildDocument(array $rules, string $language = 'de-AT'): string
    {
        $escape = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<lexicon version="1.0" xmlns="http://www.w3.org/2005/01/pronunciation-lexicon"'
            . ' alphabet="ipa" xml:lang="' . $escape($language) . '">' . "\n";

        foreach ($rules as $rule) {
            $grapheme = trim((string) ($rule['grapheme'] ?? ''));
            $value = trim((string) ($rule['value'] ?? ''));
            $type = (string) ($rule['type'] ?? 'alias');

            if ($grapheme === '' || $value === '') {
                continue;
            }

            $xml .= "  <lexeme>\n"
                . '    <grapheme>' . $escape($grapheme) . "</grapheme>\n";

            $xml .= $type === 'phoneme'
                ? '    <phoneme alphabet="ipa">' . $escape($value) . "</phoneme>\n"
                : '    <alias>' . $escape($value) . "</alias>\n";

            $xml .= "  </lexeme>\n";
        }

        return $xml . "</lexicon>\n";
    }

    /**
     * Replaces the entire rule set.
     *
     * Two calls instead of seventeen: first remove all previous terms,
     * then register the new set. This creates two versions; the last one is
     * bound. The local file is written first and validated beforehand,
     * so that an error in the template does not end up at the service.
     *
     * What needs to be removed is determined beforehand and merged from both
     * sources — otherwise a term that drops out of the set survives.
     *
     * @param list<array{grapheme:string,type:string,value:string}> $rules
     *
     * @return array{version_id:string,rules:int,entfernt:int,datei:string}
     *
     * @throws \RuntimeException
     */
    public static function replaceAll(array $rules): array
    {
        if ($rules === []) {
            throw new \RuntimeException(__('An empty rule set would empty the dictionary.', 'podcast-forge'));
        }

        // Clean up once right at the start, so that the local file and the service
        // get the same state. buildDocument() reads the result too.
        foreach ($rules as $i => $rule) {
            if ((string) $rule['type'] === 'phoneme') {
                $rules[$i]['value'] = PhonemeGuard::bereinige((string) $rule['value']);
            }
        }

        $dictionaryId = Options::get('elevenlabs_dictionary_id');
        if ($dictionaryId === '') {
            throw new \RuntimeException(__('No dictionary is configured.', 'podcast-forge'));
        }

        $key = self::apiKey();

        // 1. Determine the previous state — necessarily BEFORE writing the
        // local file. currentDocumentSource() prefers this file and would
        // otherwise read the new set as the old one; a term that drops out of
        // the set would remain at the service forever. That is exactly how the
        // two Messier rules survived being merged into "M".
        $bisher = self::obsoleteGraphemes(
            self::currentDocumentSource($key, $dictionaryId),
            self::remoteDocument($key, $dictionaryId)
        );

        // 2. Rebuild the local file and check it for readability.
        $xml = self::buildDocument($rules);
        self::writeLocal($xml);

        // 3. Remove all previous terms.
        $entfernt = 0;
        if ($bisher !== []) {
            $response = wp_remote_post(self::BASE . rawurlencode($dictionaryId) . '/remove-rules', [
                'timeout' => 30,
                'headers' => ['xi-api-key' => $key, 'content-type' => 'application/json'],
                'body'    => (string) wp_json_encode(['rule_strings' => $bisher]),
            ]);

            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200 && $code !== 404) {
                /* translators: %d: HTTP status code */
                throw new \RuntimeException(sprintf(__('Old rules cannot be removed, HTTP %d.', 'podcast-forge'), $code));
            }
            $entfernt = count($bisher);
        }

        // 4. Register the new set.
        $payload = [];
        foreach ($rules as $rule) {
            $entry = [
                'string_to_replace' => (string) $rule['grapheme'],
                'type'              => (string) $rule['type'],
            ];

            if ((string) $rule['type'] === 'phoneme') {
                $entry['phoneme'] = (string) $rule['value'];
                $entry['alphabet'] = 'ipa';
            } else {
                $entry['alias'] = (string) $rule['value'];
            }

            $payload[] = $entry;
        }

        $response = wp_remote_post(self::BASE . rawurlencode($dictionaryId) . '/add-rules', [
            'timeout' => 60,
            'headers' => ['xi-api-key' => $key, 'content-type' => 'application/json'],
            'body'    => (string) wp_json_encode(['rules' => $payload]),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'podcast-forge') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code !== 200 || !is_array($body) || !isset($body['version_id'])) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: HTTP status code, 2: start of the response body from ElevenLabs */
                __('New rules not accepted, HTTP %1$d: %2$s', 'podcast-forge'),
                $code,
                mb_substr((string) wp_remote_retrieve_body($response), 0, 300)
            ));
        }

        // 5. Bind the new version immediately.
        $all = Options::all();
        $all['elevenlabs_dictionary_version_id'] = (string) $body['version_id'];
        Options::save($all);

        return [
            'version_id' => (string) $body['version_id'],
            'rules'      => (int) ($body['version_rules_num'] ?? 0),
            'entfernt'   => $entfernt,
            'datei'      => self::path(),
        ];
    }

    public static function hasGrapheme(string $xml, string $grapheme): bool
    {
        return preg_match(
            '#<grapheme>\s*' . preg_quote(trim($grapheme), '#') . '\s*</grapheme>#u',
            $xml
        ) === 1;
    }

    /**
     * @throws \RuntimeException
     */
    private static function removeRule(string $key, string $dictionaryId, string $grapheme): void
    {
        $response = wp_remote_post(self::BASE . rawurlencode($dictionaryId) . '/remove-rules', [
            'timeout' => 30,
            'headers' => ['xi-api-key' => $key, 'content-type' => 'application/json'],
            'body'    => (string) wp_json_encode(['rule_strings' => [$grapheme]]),
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Old rule cannot be removed: ', 'podcast-forge') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // 404 means the rule does not exist there at all. No reason to complain.
        if ($code !== 200 && $code !== 404) {
            /* translators: %d: HTTP status code */
            throw new \RuntimeException(sprintf(__('Old rule cannot be removed, HTTP %d.', 'podcast-forge'), $code));
        }
    }

    /**
     * The current state: preferably the local file, otherwise the bound
     * version from the service.
     */
    /**
     * The terms that must be removed from the service before a replacement.
     *
     * Merged from all given PLS versions: the local file may know a term
     * the service does not have, and the service one that the file no longer
     * contains. Both must be removed.
     * Empty or unreadable versions are skipped, so that a missing download
     * does not prevent the replacement.
     *
     * @return list<string>
     */
    public static function obsoleteGraphemes(string ...$documents): array
    {
        $graphemes = [];

        foreach ($documents as $xml) {
            if (trim($xml) === '') {
                continue;
            }

            try {
                foreach (PlsDocument::fromString($xml)->graphemes() as $grapheme) {
                    $graphemes[$grapheme] = true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_keys($graphemes);
    }

    /**
     * The bound version at the service — without falling back to the local file.
     *
     * Returns an empty string on any failure instead of throwing:
     * the download is a safeguard, not a requirement.
     */
    private static function remoteDocument(string $key, string $dictionaryId): string
    {
        $version = Options::get('elevenlabs_dictionary_version_id');
        if ($version === '') {
            return '';
        }

        $response = wp_remote_get(
            self::BASE . rawurlencode($dictionaryId) . '/' . rawurlencode($version) . '/download',
            ['timeout' => 20, 'headers' => ['xi-api-key' => $key]]
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        return (string) wp_remote_retrieve_body($response);
    }

    private static function currentDocumentSource(string $key, string $dictionaryId): string
    {
        $path = self::path();
        if (is_readable($path)) {
            return Encoding::readFile($path);
        }

        $version = Options::get('elevenlabs_dictionary_version_id');
        if ($version === '') {
            throw new \RuntimeException(__('No dictionary version is bound.', 'podcast-forge'));
        }

        $response = wp_remote_get(
            self::BASE . rawurlencode($dictionaryId) . '/' . rawurlencode($version) . '/download',
            ['timeout' => 20, 'headers' => ['xi-api-key' => $key]]
        );

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            throw new \RuntimeException(__('The bound dictionary version could not be loaded.', 'podcast-forge'));
        }

        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * Removes all lexemes for a grapheme.
     *
     * Needed to replace a rule instead of duplicating it. With two rules for
     * the same term it is unpredictable which one wins.
     */
    public static function removeLexeme(string $xml, string $grapheme): string
    {
        $needle = preg_quote(trim($grapheme), '#');

        $pattern = '#[ \t]*<lexeme>(?:(?!</lexeme>).)*?<grapheme>\s*'
            . $needle
            . '\s*</grapheme>.*?</lexeme>\s*#su';

        return (string) preg_replace($pattern, '', $xml);
    }

    /**
     * Inserts a lexeme before the closing root element.
     */
    public static function appendLexeme(string $xml, string $grapheme, string $wert, string $art = 'alias', string $nebenform = ''): string
    {
        $escape = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lexeme = "  <lexeme>\n"
            . '    <grapheme>' . $escape($grapheme) . "</grapheme>\n";

        $lexeme .= $art === 'phoneme'
            ? '    <phoneme alphabet="ipa">' . $escape($wert) . "</phoneme>\n"
            : '    <alias>' . $escape($wert) . "</alias>\n";

        if ($nebenform !== '') {
            // The respective other form is written along as a comment, so that
            // a model change does not require rework.
            $lexeme .= '    <!-- ' . ($art === 'phoneme' ? 'Alias' : 'IPA') . ': '
                . $escape(str_replace('--', '- -', $nebenform)) . " -->\n";
        }

        $lexeme .= "  </lexeme>\n";

        $position = strripos($xml, '</lexicon>');
        if ($position === false) {
            throw new \RuntimeException(__('The PLS file has no closing lexicon element.', 'podcast-forge'));
        }

        return substr($xml, 0, $position) . $lexeme . substr($xml, $position);
    }

    private static function writeLocal(string $xml): void
    {
        // Before writing, check whether the result is readable at all.
        PlsDocument::fromString($xml);

        $path = self::path();
        $directory = dirname($path);

        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            /* translators: %s: directory path of the dictionary file */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'podcast-forge'), $directory));
        }

        if (file_put_contents($path, $xml) === false) {
            /* translators: %s: path of the dictionary file */
            throw new \RuntimeException(sprintf(__('The file %s could not be written.', 'podcast-forge'), $path));
        }
    }

    private static function apiKey(): string
    {
        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (CryptoException $e) {
            throw new \RuntimeException(__('ElevenLabs credentials could not be read: ', 'podcast-forge') . $e->getMessage(), 0, $e);
        }

        if ($key === '') {
            throw new \RuntimeException(__('No ElevenLabs API key is stored.', 'podcast-forge'));
        }

        return $key;
    }
}
