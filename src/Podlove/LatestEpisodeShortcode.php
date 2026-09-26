<?php
declare(strict_types=1);

namespace PodcastForge\Podlove;

/**
 * [aaspf_neueste_folge] — the Podlove player with the latest published
 * episode.
 *
 * The front page used to embed the player with a fixed post_id, which had to
 * be switched by hand every month; for two months the July episode stayed
 * there. This shortcode looks up the episode itself and passes it on to
 * Podlove; look and behaviour remain those of the Podlove player.
 */
final class LatestEpisodeShortcode
{
    public const TAG = 'aaspf_neueste_folge';

    public static function register(): void
    {
        add_shortcode(self::TAG, [self::class, 'render']);
        add_action('transition_post_status', [self::class, 'onTransition'], 30, 3);
    }

    /**
     * The front page sits in the page cache. When an episode is published,
     * the page would otherwise keep showing the old one until the cache expires.
     *
     * @param \WP_Post $post
     */
    public static function onTransition(string $new, string $old, $post): void
    {
        if ($new !== 'publish' || $old === 'publish' || !$post instanceof \WP_Post || $post->post_type !== EpisodeDraft::POST_TYPE) {
            return;
        }

        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
            $GLOBALS['wp_fastest_cache']->deleteCache(true);
        }
    }

    /**
     * @param array<string,string>|string $atts
     */
    public static function render($atts = []): string
    {
        $ids = get_posts([
            'post_type'        => EpisodeDraft::POST_TYPE,
            'post_status'      => 'publish',
            'orderby'          => 'date',
            'order'            => 'DESC',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
        ]);

        if ($ids === []) {
            return '';
        }

        return do_shortcode(sprintf('[podlove-episode-web-player post_id="%d"]', (int) $ids[0]));
    }
}
