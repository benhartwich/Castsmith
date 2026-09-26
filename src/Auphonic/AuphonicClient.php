<?php
declare(strict_types=1);

namespace PodcastForge\Auphonic;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use PodcastForge\Settings\Options;
use PodcastForge\Support\CryptoException;

/**
 * Production via the Auphonic JSON API.
 *
 * Deliberately not via the Podlove Auphonic integration. That one is built for
 * the "upload file, wait, fetch it back" workflow; here production is just
 * one step in a longer chain.
 *
 * There is no polling. Once it has finished its work, Auphonic calls the
 * webhook URL that this plugin provides as a REST endpoint.
 */
final class AuphonicClient
{
    private const SIMPLE   = 'https://auphonic.com/api/simple/productions.json';
    private const DETAILS  = 'https://auphonic.com/api/production/%s.json';
    private const CREATE   = 'https://auphonic.com/api/productions.json';
    private const UPLOAD   = 'https://auphonic.com/api/production/%s/upload.json';
    private const START    = 'https://auphonic.com/api/production/%s/start.json';

    /** Ducking of the opener under the first words. */
    private const INTRO_BACKGROUND_GAIN = -12;
    private const INTRO_DUCKING_FADE_MS = 750;

    private function __construct(private readonly string $token)
    {
    }

    /**
     * @throws \RuntimeException
     */
    public static function fromSettings(): self
    {
        try {
            $token = Options::secret('auphonic_api_key');
        } catch (CryptoException $e) {
            throw new \RuntimeException(__('Auphonic credentials could not be read: ', 'podcast-forge') . $e->getMessage(), 0, $e);
        }

        if ($token === '') {
            throw new \RuntimeException(__('No Auphonic token has been configured.', 'podcast-forge'));
        }

        return new self($token);
    }

    /**
     * Starts a production and returns its identifier.
     *
     * @param array<string,string> $metadata Title, subtitle, summary, keywords.
     *
     * @throws \RuntimeException
     */
    public function start(
        string $audioPath,
        string $filename,
        array $metadata,
        string $chapters,
        string $webhook,
        string $action = 'start'
    ): string {
        if (!is_readable($audioPath)) {
            /* translators: %s: path of the audio file */
            throw new \RuntimeException(sprintf(__('The audio file is not readable: %s', 'podcast-forge'), $audioPath));
        }

        $preset = Options::get('auphonic_preset');
        if ($preset === '') {
            throw new \RuntimeException(__('No Auphonic preset has been entered.', 'podcast-forge'));
        }

        $fields = array_filter(array_merge($metadata, [
            'preset'   => $preset,
            'action'   => $action,
            'webhook'  => $webhook,
            'chapters' => $chapters,
        ]), static fn ($v): bool => is_string($v) && $v !== '');

        $boundary = 'aaspf' . bin2hex(random_bytes(16));
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n";
            $body .= "Content-Type: text/plain; charset=utf-8\r\n\r\n";
            $body .= $value . "\r\n";
        }

        $audio = file_get_contents($audioPath);
        if ($audio === false) {
            throw new \RuntimeException(__('The audio file could not be read.', 'podcast-forge'));
        }

        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="input_file"; filename="' . $filename . "\"\r\n";
        $body .= "Content-Type: audio/mpeg\r\n\r\n";
        $body .= $audio . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post(self::SIMPLE, [
            'timeout' => 600,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'content-type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'podcast-forge') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code >= 400 || !is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: HTTP status code, 2: beginning of the response body */
                __('Auphonic responded with HTTP %1$d: %2$s', 'podcast-forge'),
                $code,
                mb_substr((string) wp_remote_retrieve_body($response), 0, 300)
            ));
        }

        $uuid = (string) ($decoded['data']['uuid'] ?? '');
        if ($uuid === '') {
            throw new \RuntimeException(__('Auphonic did not return a production identifier.', 'podcast-forge'));
        }

        return $uuid;
    }

    /**
     * Starts a production in which Auphonic adds opener, outro and bridges
     * (assembly without ffmpeg). Three requests: create with the description
     * of all files, upload the files, start.
     *
     * @param array<string,string>                                 $metadata title, subtitle, summary, tags (comma separated), output_basename
     * @param list<array{start_ms:int,titel:string}>               $chapters in the timeline of the speech file; Auphonic shifts them
     * @param array{intro:?array{file:string,ms:int,overlap_ms:int},outro:?array{file:string,ms:int,overlap_ms:int},inserts:list<array{file:string,at_ms:int,ms:int}>} $plan
     *
     * @throws \RuntimeException
     */
    public function startWithMusic(string $audioPath, string $filename, array $metadata, array $chapters, string $webhook, array $plan): string
    {
        $preset = Options::get('auphonic_preset');
        if ($preset === '') {
            throw new \RuntimeException(__('No Auphonic preset has been entered.', 'podcast-forge'));
        }

        $inputs = [];
        $uploads = ['input_file' => [$audioPath, $filename]];
        if ($plan['intro'] !== null) {
            $inputs[] = [
                'type'             => 'intro',
                'id'               => 'opener',
                'offset'           => round($plan['intro']['overlap_ms'] / 1000, 3),
                'backforeground'   => 'ducking',
                'backgroundgain'   => self::INTRO_BACKGROUND_GAIN,
                'ducking_fadetime' => self::INTRO_DUCKING_FADE_MS,
            ];
            $uploads['opener'] = [$plan['intro']['file'], 'opener.mp3'];
        }
        foreach ($plan['inserts'] as $n => $insert) {
            $id = 'bridge ' . ($n + 1);
            $inputs[] = ['type' => 'insert', 'id' => $id, 'offset' => round($insert['at_ms'] / 1000, 3)];
            $uploads[$id] = [$insert['file'], 'bridge-' . ($n + 1) . '.mp3'];
        }
        if ($plan['outro'] !== null) {
            $inputs[] = ['type' => 'outro', 'id' => 'outro', 'offset' => round($plan['outro']['overlap_ms'] / 1000, 3)];
            $uploads['outro'] = [$plan['outro']['file'], 'outro.mp3'];
        }

        $body = array_filter([
            'preset'            => $preset,
            'metadata'          => array_filter([
                'title'    => $metadata['title'] ?? '',
                'subtitle' => $metadata['subtitle'] ?? '',
                'summary'  => $metadata['summary'] ?? '',
                'tags'     => array_values(array_filter(array_map('trim', explode(',', $metadata['tags'] ?? '')))),
            ]),
            'output_basename'   => $metadata['output_basename'] ?? '',
            'webhook'           => $webhook,
            'chapters'          => array_map(static fn (array $c): array => [
                'start' => ChapterFormat::timecode((int) $c['start_ms']),
                'title' => (string) $c['titel'],
            ], array_values(array_filter($chapters, static fn (array $c): bool => trim((string) ($c['titel'] ?? '')) !== ''))),
            'multi_input_files' => $inputs,
        ]);

        $created = $this->request('POST', self::CREATE, (string) wp_json_encode($body), 'application/json');
        $uuid = (string) ($created['data']['uuid'] ?? '');
        if ($uuid === '') {
            throw new \RuntimeException(__('Auphonic did not return a production identifier.', 'podcast-forge'));
        }

        // One request per file. With all files in a single request Auphonic
        // attached none of the intro/outro/insert files (tested 2026-09-26).
        foreach ($uploads as $field => $file) {
            [$multipart, $boundary] = self::multipart([$field => $file]);
            $this->request('POST', sprintf(self::UPLOAD, rawurlencode($uuid)), $multipart, 'multipart/form-data; boundary=' . $boundary);
        }
        $this->request('POST', sprintf(self::START, rawurlencode($uuid)), '', 'application/json');

        return $uuid;
    }

    /**
     * @param array<string,array{0:string,1:string}> $files form field => [path, filename]
     *
     * @return array{0:string,1:string} body and boundary
     *
     * @throws \RuntimeException
     */
    private static function multipart(array $files): array
    {
        $boundary = 'aaspf' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($files as $field => [$path, $filename]) {
            $data = @file_get_contents($path);
            if ($data === false) {
                /* translators: %s: path of the audio file */
                throw new \RuntimeException(sprintf(__('The audio file is not readable: %s', 'podcast-forge'), $path));
            }
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . "\"\r\n";
            $body .= "Content-Type: audio/mpeg\r\n\r\n";
            $body .= $data . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return [$body, $boundary];
    }

    /**
     * @return array<string,mixed>
     *
     * @throws \RuntimeException
     */
    private function request(string $method, string $url, string $body, string $contentType): array
    {
        $response = wp_remote_request($url, [
            'method'  => $method,
            'timeout' => 600,
            'headers' => ['Authorization' => 'Bearer ' . $this->token, 'content-type' => $contentType],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'podcast-forge') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 400 || !is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                /* translators: 1: HTTP status code, 2: beginning of the response body */
                __('Auphonic responded with HTTP %1$d: %2$s', 'podcast-forge'),
                $code,
                mb_substr((string) wp_remote_retrieve_body($response), 0, 300)
            ));
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws \RuntimeException
     */
    public function details(string $uuid): array
    {
        $response = wp_remote_get(sprintf(self::DETAILS, rawurlencode($uuid)), [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'podcast-forge') . $response->get_error_message());
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !isset($decoded['data'])) {
            throw new \RuntimeException(__('Unexpected response from Auphonic.', 'podcast-forge'));
        }

        return (array) $decoded['data'];
    }

    /**
     * Fetches an output file of the production.
     *
     * Two steps, and that is intentional. Auphonic answers the URL from
     * `output_files` with a redirect to the storage. If that redirect is
     * followed automatically, the Authorization header travels along — and the
     * storage rejects it: "Missing x-amz-content-sha256", HTTP 400. Without the
     * header, Auphonic itself responds with 403. So first request with
     * authentication and without following redirects, then follow the redirect
     * without authentication. The token belongs in the header, not in the URL:
     * Auphonic also accepts it as a query parameter, but then it would appear
     * in every access log.
     *
     * @throws \RuntimeException
     */
    public function download(string $url): string
    {
        $response = wp_remote_get($url, [
            'timeout'     => 600,
            'redirection' => 0,
            'headers'     => ['Authorization' => 'Bearer ' . $this->token],
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Download failed: ', 'podcast-forge') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $location = (string) wp_remote_retrieve_header($response, 'location');
            if ($location === '') {
                throw new \RuntimeException(__('Auphonic redirects but does not specify a target.', 'podcast-forge'));
            }

            // Without authentication: the URL carries its own signature.
            $response = wp_remote_get($location, ['timeout' => 600, 'redirection' => 3]);

            if (is_wp_error($response)) {
                throw new \RuntimeException(__('Download failed: ', 'podcast-forge') . $response->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($response);
        }

        $body = (string) wp_remote_retrieve_body($response);

        if ($code !== 200 || $body === '') {
            throw new \RuntimeException(sprintf(
                /* translators: 1: HTTP status code, 2: optional note that authentication was rejected (or empty) */
                __('Download failed, HTTP %1$d%2$s.', 'podcast-forge'),
                $code,
                $code === 403 ? __(' — the authentication was not accepted', 'podcast-forge') : ''
            ));
        }

        return $body;
    }

}
