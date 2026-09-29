<?php
declare(strict_types=1);

namespace Sonoquill\Voice;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;

/**
 * Path A for corrections: a re-recorded passage in the timbre of the clone.
 *
 * The voice changer learns nothing. It takes audio in and returns audio,
 * preserving the emphasis and delivery of the recording. The result replaces
 * the old segment — invisibly to the user.
 *
 * Important, and enforced in the interface: **always whole sentences, never
 * single words.** The voice changer carries over the prosody of the recording;
 * a word spoken on its own carries the intonation of an isolated word and
 * sounds audibly wrong between two TTS sentences.
 */
final class VoiceChanger
{
    private const BASE  = 'https://api.elevenlabs.io/v1/speech-to-speech/';
    public const MODEL  = 'eleven_multilingual_sts_v2';

    /** The backend does not accept larger recordings. */
    public const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    private function __construct(
        private readonly string $apiKey,
        private readonly string $voiceId,
        private readonly VoiceSettings $voice,
        private readonly string $outputFormat
    ) {
    }

    /**
     * @throws \RuntimeException
     */
    public static function fromSettings(): self
    {
        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (CryptoException $e) {
            throw new \RuntimeException(__('ElevenLabs credentials could not be read: ', 'sonoquill') . $e->getMessage(), 0, $e);
        }

        if ($key === '') {
            throw new \RuntimeException(__('No ElevenLabs API key has been configured.', 'sonoquill'));
        }

        $voiceId = Options::get('elevenlabs_voice_id');
        if ($voiceId === '') {
            throw new \RuntimeException(__('No voice ID has been configured.', 'sonoquill'));
        }

        $format = Options::get('tts_output_format');

        return new self(
            $key,
            $voiceId,
            VoiceSettings::fromOptions(),
            $format !== '' ? $format : 'mp3_44100_128'
        );
    }

    /**
     * Converts a recording into the cloned voice.
     *
     * @param string $recording  Raw bytes of the recording.
     * @param string $filename   File name with extension, used for format detection.
     *
     * @throws \RuntimeException
     */
    public function convert(string $recording, string $filename, int $seed): string
    {
        if ($recording === '') {
            throw new \RuntimeException(__('The recording is empty.', 'sonoquill'));
        }

        if (strlen($recording) > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException(__('The recording is too large.', 'sonoquill'));
        }

        $boundary = 'aaspf' . bin2hex(random_bytes(16));

        $fields = [
            'model_id'                => self::MODEL,
            // Removes room reverb and background noise from the recording so
            // the re-recorded passage does not sound different from the rest.
            'remove_background_noise' => 'true',
            'seed'                    => (string) $seed,
            'voice_settings'          => (string) wp_json_encode($this->voice->toArray()),
        ];

        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="audio"; filename="' . $filename . "\"\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $recording . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $url = add_query_arg(['output_format' => $this->outputFormat], self::BASE . rawurlencode($this->voiceId));

        $response = wp_remote_post($url, [
            'timeout' => 240,
            'headers' => [
                'xi-api-key'   => $this->apiKey,
                'content-type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'sonoquill') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $result = (string) wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $decoded = json_decode($result, true);
            $detail = '';
            if (is_array($decoded) && isset($decoded['detail'])) {
                $d = $decoded['detail'];
                $detail = is_array($d) ? (string) ($d['message'] ?? $d['status'] ?? '') : (string) $d;
            }

            /* translators: 1: HTTP status code, 2: error detail from ElevenLabs */
            throw new \RuntimeException(sprintf(__('ElevenLabs responded with HTTP %1$d. %2$s', 'sonoquill'), $code, $detail));
        }

        if ($result === '') {
            throw new \RuntimeException(__('The response contains no audio.', 'sonoquill'));
        }

        return $result;
    }

    public function fileExtension(): string
    {
        return str_starts_with($this->outputFormat, 'pcm_') ? 'wav' : 'mp3';
    }
}
