<?php
declare(strict_types=1);

namespace Castsmith\Health;

use Castsmith\Settings\SettingsPage;

/**
 * Runs exactly one check and returns it as JSON.
 *
 * Deliberately one request per service. Not because of a time limit — the pool
 * allows 900 seconds — but so that a hanging service does not block the whole
 * page: six calls with a ten-second timeout each would mean a full minute of
 * blank screen when things go wrong. This way each result appears as soon as
 * it is available, and an outage stays confined to its own row.
 */
final class AjaxController
{
    public const ACTION = 'aaspf_health_check';
    public const NONCE  = 'aaspf_health';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'castsmith')], 403);
        }

        check_ajax_referer(self::NONCE, 'nonce');

        $id = isset($_POST['check']) ? sanitize_key(wp_unslash($_POST['check'])) : '';
        $check = Registry::find($id);

        if ($check === null) {
            wp_send_json_error(['message' => __('Unknown check.', 'castsmith')], 400);
        }

        try {
            $result = $check->run();
        } catch (\Throwable $e) {
            // The message of an unexpected error may be shown in the admin area;
            // it contains no credentials — those are never put into messages anywhere.
            $result = Result::fail(__('The check aborted with an error.', 'castsmith'), $e->getMessage());
        }

        Registry::remember($check->id(), $result);

        wp_send_json_success([
            'id'    => $check->id(),
            'label' => $check->label(),
        ] + $result->toArray());
    }
}
