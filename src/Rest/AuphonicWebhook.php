<?php
declare(strict_types=1);

namespace Sonoquill\Rest;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\Schema;
use Sonoquill\Pipeline\Scheduler;
use Sonoquill\Settings\Options;

/**
 * The callback from Auphonic.
 *
 * The callback URL is registered as a REST endpoint instead of polling. A
 * production takes minutes; polling would either be sluggish or waste
 * requests.
 *
 * The endpoint is public — Auphonic does not bring any authentication — and
 * is therefore secured in three ways: a secret in the path, a check of the
 * production ID against our own database, and no work at all in the
 * request itself. It only queues a job and responds.
 */
final class AuphonicWebhook
{
    public const NAMESPACE = 'sonoquill/v1';
    public const ROUTE     = '/auphonic/(?P<token>[A-Za-z0-9]{16,64})';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoute']);
    }

    public static function registerRoute(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle'],
            // Auphonic cannot log in; it proves itself with the secret token
            // that is part of the callback URL.
            'permission_callback' => [self::class, 'authorize'],
            'args'                => [
                'token'         => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'uuid'          => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'status'        => ['sanitize_callback' => 'sanitize_text_field'],
                'status_string' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public static function url(): string
    {
        return rest_url(self::NAMESPACE . '/auphonic/' . self::token());
    }

    public static function token(): string
    {
        $token = Options::get('auphonic_webhook_token');

        if ($token === '') {
            $token = wp_generate_password(32, false);
            $all = Options::all();
            $all['auphonic_webhook_token'] = $token;
            Options::save($all);
        }

        return $token;
    }

    /**
     * Only a request with the stored token gets through. Without a stored
     * token nothing does — the token is created when a production is started.
     */
    public static function authorize(\WP_REST_Request $request): bool
    {
        $token = Options::get('auphonic_webhook_token');

        return $token !== '' && hash_equals($token, (string) $request->get_param('token'));
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $uuid = trim((string) ($request->get_param('uuid') ?? ''));
        if ($uuid === '') {
            return new \WP_REST_Response(['ok' => false, 'grund' => 'missing uuid'], 400);
        }

        $episodeId = self::episodeByProduction($uuid);
        if ($episodeId === 0) {
            // A production we did not start is none of our business.
            return new \WP_REST_Response(['ok' => false, 'grund' => 'unbekannt'], 404);
        }

        $status = (string) ($request->get_param('status_string') ?? $request->get_param('status') ?? '');

        EpisodeRepository::log($episodeId, 'auphonic', sprintf(
            /* translators: %s: Auphonic production status reported in the callback */
            __('Callback received, status "%s".', 'sonoquill'),
            $status !== '' ? $status : __('unknown', 'sonoquill')
        ));

        // No work is done in the callback itself: downloading the finished
        // file takes time, and Auphonic should not have to wait for it.
        Scheduler::queuePublish($episodeId);

        return new \WP_REST_Response(['ok' => true], 200);
    }

    private static function episodeByProduction(string $uuid): int
    {
        global $wpdb;

        $table = Schema::episodesTable();
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE auphonic_production_uuid = %s LIMIT 1",
            $uuid
        ));

        return $id === null ? 0 : (int) $id;
    }
}
