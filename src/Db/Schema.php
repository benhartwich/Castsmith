<?php
declare(strict_types=1);

namespace Castsmith\Db;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating and upgrading the plugin's own tables.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().

/**
 * Creates the plugin's two tables (episodes and segments) and keeps them up to date.
 *
 * For the segments, instead of a single `dict_version` column there are two.
 * `dict_version_id` records which dictionary version
 * actually produced this audio — its provenance. `dict_fingerprint` records
 * whether anything has changed for this segment, and feeds into `hash`.
 * Combining both in one column would mean that every dictionary edit
 * regenerates the whole episode, instead of only the segments that contain
 * the affected term.
 *
 * The state of an episode is too structured for postmeta: an episode is an
 * ordered list of segments, and partial regeneration relies on exactly that.
 *
 * Where the text of an episode comes from is stored in `source_type` (the
 * identifier of a registered source, see Source\Sources). `source_ref` is a
 * short key of the source — the month of a sky preview, the ID of a post —,
 * and `source_json` holds its intermediate state. A separate table per source
 * is not worth it: the data is always read as a whole, and it belongs to
 * exactly one episode.
 *
 * A fresh installation only creates the tables. The steps in upgrade() are
 * for installations of earlier versions and only run there.
 *
 * Timestamps are deliberately nullable instead of '0000-00-00 00:00:00', so
 * that the schema also holds up under a strict sql_mode.
 */
final class Schema
{
    public static function episodesTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aaspf_episodes';
    }

    public static function segmentsTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'aaspf_segments';
    }

    /**
     * Runs the migration if the stored schema version differs.
     */
    public static function maybeInstall(): void
    {
        if (get_option('aaspf_db_version') === AASPF_DB_VERSION) {
            return;
        }

        self::install();
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $episodes = self::episodesTable();
        $segments = self::segmentsTable();

        // The formatting follows the requirements of dbDelta: one field per line,
        // two spaces after PRIMARY KEY, named indexes, the keyword KEY.
        $sqlEpisodes = "CREATE TABLE {$episodes} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	status varchar(32) NOT NULL DEFAULT 'new',
	source_filename varchar(255) NOT NULL DEFAULT '',
	source_text longtext NULL,
	source_blocks longtext NULL,
	script_text longtext NULL,
	episode_title varchar(255) NOT NULL DEFAULT '',
	description_short text NULL,
	description_long longtext NULL,
	keywords text NULL,
	pronunciation_candidates text NULL,
	diff_json longtext NULL,
	diff_acknowledged text NULL,
	guard_json text NULL,
	factcheck_json longtext NULL,
	dictionary_added text NULL,
	chapters longtext NULL,
	ai_disclosure_text text NULL,
	ai_disclosure_in_audio tinyint(1) NOT NULL DEFAULT 0,
	voice_id varchar(64) NOT NULL DEFAULT '',
	voice_settings text NULL,
	tts_model_id varchar(64) NOT NULL DEFAULT '',
	dict_id varchar(64) NOT NULL DEFAULT '',
	dict_version_id varchar(64) NOT NULL DEFAULT '',
	mixed_audio_path varchar(255) NOT NULL DEFAULT '',
	duration_ms int(10) unsigned NULL,
	auphonic_production_uuid varchar(64) NOT NULL DEFAULT '',
	podlove_post_id bigint(20) unsigned NULL,
	podlove_slug varchar(191) NOT NULL DEFAULT '',
	production_url varchar(255) NOT NULL DEFAULT '',
	chars_billed int(10) unsigned NOT NULL DEFAULT 0,
	cost_cents decimal(10,2) NOT NULL DEFAULT 0,
	run_log longtext NULL,
	text_approved_at datetime NULL,
	audio_approved_at datetime NULL,
	source_type varchar(32) NOT NULL DEFAULT 'upload',
	source_ref varchar(64) NOT NULL DEFAULT '',
	source_json longtext NULL,
	montage_json longtext NULL,
	auto_chain tinyint(1) NOT NULL DEFAULT 0,
	text_notified_at datetime NULL,
	audio_notified_at datetime NULL,
	created_at datetime NULL,
	updated_at datetime NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY created_at (created_at),
	KEY source (source_type,source_ref)
) {$charset};";

        $sqlSegments = "CREATE TABLE {$segments} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	episode_id bigint(20) unsigned NOT NULL,
	idx int(10) unsigned NOT NULL,
	text longtext NULL,
	kind varchar(20) NOT NULL DEFAULT 'body',
	chapter_title varchar(255) NOT NULL DEFAULT '',
	audio_path varchar(255) NOT NULL DEFAULT '',
	source varchar(10) NOT NULL DEFAULT 'tts',
	seed bigint(20) unsigned NULL,
	dict_version_id varchar(64) NOT NULL DEFAULT '',
	dict_fingerprint char(64) NOT NULL DEFAULT '',
	duration_ms int(10) unsigned NULL,
	status varchar(20) NOT NULL DEFAULT 'pending',
	hash char(64) NOT NULL DEFAULT '',
	alignment_json longtext NULL,
	note text NULL,
	created_at datetime NULL,
	updated_at datetime NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY episode_idx (episode_id,idx),
	KEY episode_status (episode_id,status),
	KEY hash (hash)
) {$charset};";

        $previous = get_option('aaspf_db_version');
        if ($previous !== false) {
            // Before dbDelta: renamed tables must exist under their new name,
            // otherwise dbDelta would create empty ones next to them.
            self::upgrade((int) $previous, $episodes, $segments);
        }

        dbDelta($sqlEpisodes);
        dbDelta($sqlSegments);

        // The version is only recorded once both tables are there; otherwise
        // the next request tries again.
        if (in_array(false, self::status(), true)) {
            return;
        }

        // Autoloaded: maybeInstall() compares it on every request.
        update_option('aaspf_db_version', AASPF_DB_VERSION, true);
    }

    /**
     * Steps for installations of earlier schema versions. Each step checks
     * the actual state first and can run more than once.
     */
    private static function upgrade(int $from, string $episodes, string $segments): void
    {
        global $wpdb;

        // Version 11: the tables were called {prefix}aas_episodes and
        // {prefix}aas_segments.
        foreach ([$wpdb->prefix . 'aas_episodes' => $episodes, $wpdb->prefix . 'aas_segments' => $segments] as $old => $new) {
            if (self::tableExists($old) && !self::tableExists($new)) {
                $wpdb->query("RENAME TABLE {$old} TO {$new}");
            }
        }

        // The former combined column `dict_version` was split into
        // provenance and fingerprint; dbDelta does not remove columns.
        if (self::tableExists($segments) && self::columnExists($segments, 'dict_version')) {
            $wpdb->query("ALTER TABLE {$segments} DROP COLUMN dict_version");
        }

        if ($from < 9 && self::tableExists($episodes)) {
            self::migrateToSources($episodes);
        }

        if ($from < 11) {
            self::moveMusic();

            // Storage paths are no longer settings (see EpisodeStorage).
            $settings = get_option(\Castsmith\Settings\Options::OPTION);
            if (is_array($settings) && (isset($settings['storage_dir']) || isset($settings['dictionary_file']))) {
                unset($settings['storage_dir'], $settings['dictionary_file']);
                update_option(\Castsmith\Settings\Options::OPTION, $settings, false);
            }
        }
    }

    /**
     * Version 11: music moved from uploads/aaspf-musik into the plugin's
     * uploads folder.
     */
    private static function moveMusic(): void
    {
        $uploads = wp_upload_dir(null, false);
        $old = untrailingslashit((string) $uploads['basedir']) . '/aaspf-musik';
        $new = \Castsmith\Audio\MusicBed::dir();

        if (is_dir($old) && !file_exists($new) && wp_mkdir_p(dirname($new))) {
            @rename($old, $new); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving the plugin's own folder within uploads.
        }
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column)) !== null;
    }

    /**
     * Schema version 9: the sky preview was the only source with its own
     * columns (`sky_month`/`sky_json`). Its data moves into the generic
     * source columns, and its two states now have generic names. Only what
     * is still missing gets copied.
     */
    private static function migrateToSources(string $episodes): void
    {
        global $wpdb;

        if (self::columnExists($episodes, 'sky_month')) {
            $wpdb->query("UPDATE {$episodes} SET source_type = 'sky', source_ref = sky_month, source_json = sky_json WHERE sky_month <> '' AND source_json IS NULL");
        }

        $wpdb->query("UPDATE {$episodes} SET status = 'quelle_laeuft' WHERE status = 'himmel_laeuft'");
        $wpdb->query("UPDATE {$episodes} SET status = 'quelle_fehler' WHERE status = 'himmel_fehler'");
    }

    /**
     * @return array<string,bool> table name => exists
     */
    public static function status(): array
    {
        global $wpdb;

        $result = [];
        foreach ([self::episodesTable(), self::segmentsTable()] as $table) {
            $result[$table] = self::tableExists($table);
        }

        return $result;
    }

    public static function dropAll(): void
    {
        global $wpdb;

        foreach ([self::segmentsTable(), self::episodesTable()] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option('aaspf_db_version');
    }
}
