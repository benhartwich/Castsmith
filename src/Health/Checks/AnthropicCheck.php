<?php
declare(strict_types=1);

namespace PodcastForge\Health\Checks;

use PodcastForge\Health\CheckInterface;
use PodcastForge\Health\Result;
use PodcastForge\Settings\Options;
use PodcastForge\Support\CryptoException;

/**
 * Checks Anthropic via GET /v1/models/{id}.
 *
 * The endpoint costs no tokens and answers two questions at once:
 * does the key work, and does the configured model exist at all.
 * A 401 comes from the key, a 404 from the model name — which makes
 * it easy to tell the two apart cleanly.
 */
final class AnthropicCheck implements CheckInterface
{
    private const BASE = 'https://api.anthropic.com/v1/models/';
    private const API_VERSION = '2023-06-01';

    public function id(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Anthropic';
    }

    public function run(): Result
    {
        try {
            $key = Options::secret('anthropic_api_key');
        } catch (CryptoException $e) {
            return Result::fail(__('Credentials could not be read.', 'podcast-forge'), $e->getMessage());
        }

        if ($key === '') {
            return Result::skip(__('No API key stored.', 'podcast-forge'));
        }

        $model = Options::get('anthropic_model');
        if ($model === '') {
            return Result::warn(__('No model configured.', 'podcast-forge'));
        }

        $headers = [
            'x-api-key'         => $key,
            'anthropic-version' => self::API_VERSION,
            'accept'            => 'application/json',
        ];

        // Identity-bound keys additionally require the workspace.
        $workspace = Options::get('anthropic_workspace_id');
        if ($workspace !== '') {
            $headers['anthropic-workspace-id'] = $workspace;
        }

        $response = wp_remote_get(self::BASE . rawurlencode($model), [
            'timeout' => 10,
            'headers' => $headers,
        ]);

        if (is_wp_error($response)) {
            return Result::fail(__('Could not reach the service.', 'podcast-forge'), $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return Result::fail(__('API key not accepted (HTTP 401).', 'podcast-forge'));
        }

        if ($code === 404) {
            return Result::warn(
                /* translators: %s: configured Anthropic model ID */
                sprintf(__('Key is valid, but model "%s" is unknown.', 'podcast-forge'), $model),
                __('Check the model name in the settings.', 'podcast-forge')
            );
        }

        $error = is_array($body) && isset($body['error']['message'])
            ? (string) $body['error']['message']
            : '';

        if ($code === 400 && str_contains($error, 'anthropic-workspace-id')) {
            return Result::fail(
                __('The key is identity-bound and requires a workspace ID.', 'podcast-forge'),
                __('Enter the workspace ID in the settings — it appears in the address bar of the Anthropic console when the workspace is open. Alternatively, create a key that is already bound to a workspace.', 'podcast-forge')
            );
        }

        if ($code !== 200 || !is_array($body)) {
            /* translators: %d: HTTP status code */
            return Result::fail(sprintf(__('Unexpected response (HTTP %d).', 'podcast-forge'), $code), $error);
        }

        $name = isset($body['display_name']) ? (string) $body['display_name'] : $model;

        /* translators: %s: display name of the model */
        return Result::ok(sprintf(__('Reachable, model "%s" available.', 'podcast-forge'), $name));
    }
}
