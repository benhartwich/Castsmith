<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

use PodcastForge\Db\EpisodeRepository;
use PodcastForge\Segments\SegmentRepository;
use PodcastForge\Settings\SettingsPage;

/**
 * The state of an episode as JSON, for the live display in the episode view.
 *
 * While a step is running on its own, the page polls every fifteen seconds,
 * updates the progress and reloads itself as soon as a different station is
 * up. Previously you had to reload the page yourself to see this.
 */
final class ProgressController
{
    public const ACTION = 'aaspf_folge_stand';
    public const NONCE = 'aaspf_folge_stand';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_send_json_error(null, 403);
        }

        check_ajax_referer(self::NONCE, 'nonce');

        $episode = EpisodeRepository::find(isset($_POST['episode']) ? (int) $_POST['episode'] : 0);
        if ($episode === null) {
            wp_send_json_error(null, 404);
        }

        $current = Workflow::current(Workflow::stations($episode));
        $summary = SegmentRepository::summary((int) $episode['id']);

        wp_send_json_success([
            'key'    => $current['key'] ?? '',
            'human'  => $current !== null && Workflow::needsHuman($episode, $current['key']),
            'fertig' => $summary['erzeugt'] + $summary['beanstandet'],
            'gesamt' => $summary['gesamt'],
        ]);
    }
}
