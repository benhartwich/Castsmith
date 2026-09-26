<?php
declare(strict_types=1);

namespace PodcastForge\Health\Checks;

use PodcastForge\Health\CheckInterface;
use PodcastForge\Health\Result;
use PodcastForge\Settings\Options;
use PodcastForge\Support\CryptoException;

/**
 * Checks ElevenLabs via /v1/user/subscription.
 *
 * This endpoint is free of charge and also returns the character usage of the
 * quota — the basis for the cost display.
 */
final class ElevenLabsCheck implements CheckInterface
{
    private const ENDPOINT = 'https://api.elevenlabs.io/v1/user/subscription';

    public function id(): string
    {
        return 'elevenlabs';
    }

    public function label(): string
    {
        return 'ElevenLabs';
    }

    public function run(): Result
    {
        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (CryptoException $e) {
            return Result::fail(__('Credentials could not be read.', 'podcast-forge'), $e->getMessage());
        }

        if ($key === '') {
            return Result::skip(__('No API key configured.', 'podcast-forge'));
        }

        $response = wp_remote_get(self::ENDPOINT, [
            'timeout' => 10,
            'headers' => [
                'xi-api-key' => $key,
                'accept'     => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return Result::fail(__('Could not be reached.', 'podcast-forge'), $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return Result::fail(__('API key not accepted (HTTP 401).', 'podcast-forge'), $this->shapeHint($key));
        }

        if ($code !== 200 || !is_array($body)) {
            /* translators: %d: HTTP status code */
            return Result::fail(sprintf(__('Unexpected response (HTTP %d).', 'podcast-forge'), $code));
        }

        $used  = isset($body['character_count']) ? (int) $body['character_count'] : null;
        $limit = isset($body['character_limit']) ? (int) $body['character_limit'] : null;
        $tier  = isset($body['tier']) ? (string) $body['tier'] : '';

        $detail = '';
        if ($used !== null && $limit !== null && $limit > 0) {
            $detail = sprintf(
                /* translators: 1: number of characters used, 2: character limit of the quota, 3: percentage of the quota used */
                __('Quota: %1$s of %2$s characters used (%3$d %%).', 'podcast-forge'),
                number_format_i18n($used),
                number_format_i18n($limit),
                (int) round($used / $limit * 100)
            );
        }

        $voiceId = Options::get('elevenlabs_voice_id');
        if ($voiceId === '') {
            return Result::warn(__('Reachable, but no voice ID configured.', 'podcast-forge'), trim($tier . ' ' . $detail));
        }

        return Result::ok(
            /* translators: %s: name of the ElevenLabs subscription plan */
            $tier !== '' ? sprintf(__('Reachable (plan %s).', 'podcast-forge'), $tier) : __('Reachable.', 'podcast-forge'),
            $detail
        );
    }

    /**
     * Hint about the shape of the stored value, without revealing it.
     *
     * A 401 on its own does not tell whether the key has expired or whether a
     * completely different value was accidentally entered in the field. The
     * length and the prefix answer that without displaying the secret.
     */
    private function shapeHint(string $key): string
    {
        if (str_starts_with($key, 'sk_')) {
            return __('The format looks right — the key has probably expired or been revoked.', 'podcast-forge');
        }

        return sprintf(
            /* translators: %d: length of the stored API key in characters */
            __('The stored value is %d characters long and does not start with "sk_". ElevenLabs keys start with "sk_". Is it perhaps a different value copied from the dashboard?', 'podcast-forge'),
            strlen($key)
        );
    }
}
