<?php
declare(strict_types=1);

namespace Sonoquill\Voice;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;

/**
 * Speech synthesis via ElevenLabs, one call per segment.
 *
 * Three decisions are built in here:
 *
 * - The "with-timestamps" endpoint, because the character timestamps carry
 *   the chapter markers and the transcript.
 * - `previous_text` and `next_text` with the neighbouring text, otherwise the
 *   prosody breaks at paragraph boundaries.
 * - The dictionary is bound with `pronunciation_dictionary_id` AND `version_id`,
 *   never without a version. Without the version, later maintenance of the
 *   dictionary would retroactively change the result of a rerun.
 */
final class TextToSpeech
{
    private const BASE = 'https://api.elevenlabs.io/v1/text-to-speech/';

    private function __construct(
        private readonly string $apiKey,
        private readonly string $voiceId,
        private readonly string $modelId,
        private readonly VoiceSettings $voice,
        private readonly string $outputFormat,
        private readonly string $dictionaryId,
        private readonly string $dictionaryVersionId
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
            throw new \RuntimeException(__('No ElevenLabs API key has been stored.', 'sonoquill'));
        }

        $voiceId = Options::get('elevenlabs_voice_id');
        if ($voiceId === '') {
            throw new \RuntimeException(__('No voice ID has been stored.', 'sonoquill'));
        }

        $dictionaryId = Options::get('elevenlabs_dictionary_id');
        $dictionaryVersion = Options::get('elevenlabs_dictionary_version_id');

        if ($dictionaryId !== '' && $dictionaryVersion === '') {
            throw new \RuntimeException(
                __('The dictionary is entered without a version. Without an explicit version, ', 'sonoquill')
                . __('later maintenance would retroactively change the result of a rerun.', 'sonoquill')
            );
        }

        $format = Options::get('tts_output_format');

        return new self(
            $key,
            $voiceId,
            Options::get('elevenlabs_model_id') !== '' ? Options::get('elevenlabs_model_id') : 'eleven_multilingual_v2',
            VoiceSettings::fromOptions(),
            $format !== '' ? $format : 'mp3_44100_128',
            $dictionaryId,
            $dictionaryVersion
        );
    }

    public function outputFormat(): string
    {
        return $this->outputFormat;
    }

    /**
     * Some models reject `previous_text` and `next_text` (see Models).
     * Without this distinction every single call would fail after switching
     * the model.
     */
    public function supportsNeighbourText(): bool
    {
        return Models::acceptsNeighbourText($this->modelId);
    }

    public static function modelSupportsNeighbourText(string $modelId): bool
    {
        return Models::acceptsNeighbourText($modelId);
    }

    public function fileExtension(): string
    {
        return str_starts_with($this->outputFormat, 'pcm_') ? 'wav' : 'mp3';
    }

    /**
     * @throws \RuntimeException
     */
    public function synthesize(string $text, int $seed, string $previousText = '', string $nextText = ''): TtsResult
    {
        $payload = [
            'text'          => $text,
            'model_id'      => $this->modelId,
            'voice_settings'=> $this->voice->toArray(),
            'seed'          => $seed,
        ];

        if ($this->supportsNeighbourText()) {
            if ($previousText !== '') {
                $payload['previous_text'] = $previousText;
            }
            if ($nextText !== '') {
                $payload['next_text'] = $nextText;
            }
        }

        if ($this->dictionaryId !== '') {
            $payload['pronunciation_dictionary_locators'] = [[
                'pronunciation_dictionary_id' => $this->dictionaryId,
                'version_id'                  => $this->dictionaryVersionId,
            ]];
        }

        $url = add_query_arg(
            ['output_format' => $this->outputFormat],
            self::BASE . rawurlencode($this->voiceId) . '/with-timestamps'
        );

        $body = wp_json_encode($payload);
        if ($body === false) {
            throw new \RuntimeException(__('The request could not be encoded as JSON.', 'sonoquill'));
        }

        $response = wp_remote_post($url, [
            'timeout' => 180,
            'headers' => [
                'xi-api-key'   => $this->apiKey,
                'content-type' => 'application/json',
                'accept'       => 'application/json',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'sonoquill') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code !== 200 || !is_array($decoded)) {
            $detail = '';
            if (is_array($decoded) && isset($decoded['detail'])) {
                $d = $decoded['detail'];
                $detail = is_array($d)
                    ? (string) ($d['message'] ?? $d['status'] ?? '')
                    : (string) $d;
            }

            /* translators: 1: HTTP status code, 2: error detail returned by ElevenLabs */
            throw new \RuntimeException(sprintf(__('ElevenLabs request failed with HTTP %1$d. %2$s', 'sonoquill'), $code, $detail));
        }

        $audio = base64_decode((string) ($decoded['audio_base64'] ?? ''), true);
        if ($audio === false || $audio === '') {
            throw new \RuntimeException(__('Synthesis failed: the response contains no audio.', 'sonoquill'));
        }

        // The normalized alignment reflects what was actually spoken. For our
        // script both are almost identical, because it contains no digits —
        // we use the one that refers to our own text.
        $alignment = $decoded['alignment'] ?? $decoded['normalized_alignment'] ?? [];

        return new TtsResult(
            $audio,
            is_array($alignment) ? $alignment : [],
            mb_strlen($text)
        );
    }
}
