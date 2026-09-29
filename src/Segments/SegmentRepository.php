<?php
declare(strict_types=1);

namespace Sonoquill\Segments;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().

use Sonoquill\Db\Schema;

final class SegmentRepository
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function forEpisode(int $episodeId): array
    {
        global $wpdb;

        $table = Schema::segmentsTable();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE episode_id = %d ORDER BY idx ASC", $episodeId),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        global $wpdb;

        $table = Schema::segmentsTable();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByIndex(int $episodeId, int $index): ?array
    {
        global $wpdb;

        $table = Schema::segmentsTable();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE episode_id = %d AND idx = %d", $episodeId, $index),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function insert(array $fields): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->insert(Schema::segmentsTable(), array_merge([
            'created_at' => $now,
            'updated_at' => $now,
        ], $fields));

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function update(int $id, array $fields): void
    {
        global $wpdb;

        $fields['updated_at'] = current_time('mysql');
        $wpdb->update(Schema::segmentsTable(), $fields, ['id' => $id]);
    }

    public static function deleteForEpisode(int $episodeId): void
    {
        global $wpdb;

        $wpdb->delete(Schema::segmentsTable(), ['episode_id' => $episodeId]);
    }

    /**
     * Segments whose audio is missing or whose hash no longer matches.
     *
     * @return list<array<string,mixed>>
     */
    public static function stale(int $episodeId): array
    {
        $stale = [];

        foreach (self::forEpisode($episodeId) as $segment) {
            if ((string) $segment['audio_path'] === '' || (string) $segment['status'] === SegmentStatus::PENDING) {
                $stale[] = $segment;
            }
        }

        return $stale;
    }

    /**
     * @return array{gesamt:int,erzeugt:int,offen:int,beanstandet:int,dauer_ms:int}
     */
    public static function summary(int $episodeId): array
    {
        $summary = ['gesamt' => 0, 'erzeugt' => 0, 'offen' => 0, 'beanstandet' => 0, 'dauer_ms' => 0];

        foreach (self::forEpisode($episodeId) as $segment) {
            $summary['gesamt']++;
            $summary['dauer_ms'] += (int) $segment['duration_ms'];

            $status = (string) $segment['status'];
            if ($status === SegmentStatus::PENDING) {
                $summary['offen']++;
            } elseif ($status === SegmentStatus::FLAGGED) {
                $summary['beanstandet']++;
            } else {
                $summary['erzeugt']++;
            }
        }

        return $summary;
    }
}
