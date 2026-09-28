<?php
declare(strict_types=1);

namespace Castsmith\Jobs;

use Castsmith\Settings\SettingsPage;

/**
 * The "Schedule test job" button on the settings page.
 */
final class AdminActions
{
    public const ACTION       = 'aaspf_schedule_ping';
    public const NONCE_ACTION = 'aaspf_schedule_ping';

    private const NOTICE_TRANSIENT = 'aaspf_admin_notice_';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handleSchedulePing']);
    }

    public static function handleSchedulePing(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'castsmith'), '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE_ACTION);

        $result = Scheduler::schedulePing();

        set_transient(
            self::NOTICE_TRANSIENT . get_current_user_id(),
            [
                'type'    => $result['ok'] ? 'success' : 'error',
                'message' => $result['message'],
            ],
            60
        );

        wp_safe_redirect(\Castsmith\Admin\Urls::settings());
        exit;
    }

    /**
     * Displays the feedback message exactly once.
     */
    public static function renderNotice(): void
    {
        $key    = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);

        if (!is_array($notice) || !isset($notice['type'], $notice['message'])) {
            return;
        }

        delete_transient($key);

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr((string) $notice['type']),
            esc_html((string) $notice['message'])
        );
    }
}
