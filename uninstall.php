<?php
/**
 * Cleanup when the plugin is deleted.
 *
 * Tables are only removed if this was explicitly requested in the settings.
 * Accidentally deleting the plugin should not destroy the work on an
 * episode in progress.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on request.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names come from $wpdb->prefix; there are no other values.

$aaspf_settings = get_option('aaspf_settings', []);
$aaspf_purge = is_array($aaspf_settings) && !empty($aaspf_settings['delete_data_on_uninstall']);

if ($aaspf_purge) {
    global $wpdb;

    foreach ([$wpdb->prefix . 'aas_segments', $wpdb->prefix . 'aas_episodes'] as $aaspf_table) {
        $wpdb->query("DROP TABLE IF EXISTS {$aaspf_table}");
    }

    // Only on complete removal: without this name component, a later
    // reinstallation would not find a retained storage location again.
    delete_option('aaspf_storage_suffix');
}

delete_option('aaspf_settings');
delete_option('aaspf_db_version');
delete_option('aaspf_ping_state');
