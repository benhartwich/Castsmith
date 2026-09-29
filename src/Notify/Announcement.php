<?php
declare(strict_types=1);

namespace Sonoquill\Notify;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\Schema;

/**
 * A prepared e-mail that is sent exactly when a specific Podlove episode
 * is published — for example, the request to the board to forward an
 * episode to all members.
 *
 * It is stored as an option: episode, recipients, subject, body. {titel}
 * and {link} are filled in at send time, because both may still change
 * before publication. After a successful send the option is deleted, so
 * the e-mail goes out exactly once.
 */
final class Announcement
{
    public const OPTION = 'aaspf_ankuendigung';

    public static function register(): void
    {
        add_action('transition_post_status', [self::class, 'onTransition'], 20, 3);
    }

    /**
     * @param \WP_Post $post
     */
    public static function onTransition(string $new, string $old, $post): void
    {
        if ($new !== 'publish' || $old === 'publish' || !$post instanceof \WP_Post) {
            return;
        }

        $mail = get_option(self::OPTION);
        if (!is_array($mail) || (int) ($mail['post_id'] ?? 0) !== (int) $post->ID) {
            return;
        }

        $replace = [
            '{titel}' => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
            '{link}'  => (string) get_permalink($post),
        ];

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        foreach ((array) ($mail['cc'] ?? []) as $cc) {
            $headers[] = 'Cc: ' . $cc;
        }
        if (!empty($mail['reply_to'])) {
            $headers[] = 'Reply-To: ' . $mail['reply_to'];
        }

        $ok = wp_mail(
            (array) $mail['to'],
            strtr((string) $mail['subject'], $replace),
            strtr((string) $mail['body'], $replace),
            $headers
        );

        $episodeId = self::episodeFor((int) $post->ID);

        if ($ok) {
            delete_option(self::OPTION);
            if ($episodeId !== null) {
                /* translators: %s: comma-separated list of recipient addresses */
                EpisodeRepository::log($episodeId, 'mail', sprintf(__('Announcement sent to %s.', 'sonoquill'), implode(', ', (array) $mail['to'])));
            }

            return;
        }

        if ($episodeId !== null) {
            /* translators: %s: comma-separated list of recipient addresses */
            EpisodeRepository::log($episodeId, 'mail', sprintf(__('Announcement to %s failed; it remains queued.', 'sonoquill'), implode(', ', (array) $mail['to'])));
        }
    }

    private static function episodeFor(int $postId): ?int
    {
        global $wpdb;

        $table = Schema::episodesTable();
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE podlove_post_id = %d ORDER BY id DESC LIMIT 1", $postId));

        return $id !== null ? (int) $id : null;
    }
}
