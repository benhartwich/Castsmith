<?php
declare(strict_types=1);

namespace Sonoquill\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters of admin screens.

use Sonoquill\Settings\SettingsPage;

/**
 * All backend addresses in one place.
 *
 * Since 25.09.2026 the plugin has its own menu item, like the gallery
 * plugin, instead of two entries under "Tools". Links in mails that have
 * already been sent still point to tools.php — redirectLegacy() forwards
 * those.
 */
final class Urls
{
    /**
     * @param array<string,scalar> $args
     */
    public static function episodes(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => EpisodesPage::MENU_SLUG], $args), admin_url('admin.php'));
    }

    /**
     * A single episode, optionally with a tab already open.
     */
    public static function episode(int $id, string $tab = ''): string
    {
        return self::episodes(['episode' => $id]) . ($tab !== '' ? '#reiter-' . $tab : '');
    }

    public static function settings(): string
    {
        return add_query_arg('page', SettingsPage::MENU_SLUG, admin_url('admin.php'));
    }


    /**
     * Redirect tools.php?page=… from the time before the plugin had its own menu to admin.php.
     */
    public static function redirectLegacy(): void
    {
        global $pagenow;

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        $ours = [EpisodesPage::MENU_SLUG, SettingsPage::MENU_SLUG];

        if ($pagenow !== 'tools.php' || !in_array($page, $ours, true)) {
            return;
        }

        $args = array_map(
            static fn ($value) => is_string($value) ? sanitize_text_field(wp_unslash($value)) : '',
            $_GET
        );

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
