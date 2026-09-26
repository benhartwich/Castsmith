<?php
declare(strict_types=1);

namespace PodcastForge;

use PodcastForge\Db\Schema;
use PodcastForge\Admin\EpisodeActions;
use PodcastForge\Admin\AudioStream;
use PodcastForge\Admin\DictionaryController;
use PodcastForge\Admin\EpisodesPage;
use PodcastForge\Admin\PatchController;
use PodcastForge\Health\AjaxController;
use PodcastForge\Jobs\AdminActions;
use PodcastForge\Jobs\Scheduler;
use PodcastForge\Pipeline\Scheduler as PipelineScheduler;
use PodcastForge\Settings\SettingsPage;

/**
 * Hooks the plugin into WordPress. Nothing else.
 */
final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        // The job handler must be registered even when no admin backend is
        // involved — Action Scheduler processes the queue via WP-Cron in the
        // frontend context.
        Scheduler::register();
        PipelineScheduler::register();
        \PodcastForge\Rest\AuphonicWebhook::register();
        // Translations everywhere, not only in the admin: mails and run-log
        // entries are written by background jobs, too.
        add_action('init', [$this, 'loadTextdomain']);
        // Schema updates on every request, not only in the admin: after an
        // update a background job may run before anyone opens the backend.
        add_action('init', [Schema::class, 'maybeInstall'], 1);
        // Changed paths or mode: check for ffmpeg again.
        add_action('update_option_' . \PodcastForge\Settings\Options::OPTION, [\PodcastForge\Audio\AudioEngine::class, 'forget'], 10, 0);
        // Publishing also happens from within the Podlove backend, so this is not admin-only.
        \PodcastForge\Notify\Announcement::register();
        // The only frontend output: the player with the latest episode.
        \PodcastForge\Podlove\LatestEpisodeShortcode::register();

        // Otherwise the plugin produces no frontend output. Translations are
        // therefore only loaded where text is actually displayed.
        if (is_admin()) {
            SettingsPage::register();
            EpisodesPage::register();
            \PodcastForge\Admin\PromptsPage::register();
            EpisodeActions::register();
            AudioStream::register();
            PatchController::register();
            DictionaryController::register();
            AjaxController::register();
            AdminActions::register();
            \PodcastForge\Admin\ProgressController::register();
            add_action('admin_init', [\PodcastForge\Admin\Urls::class, 'redirectLegacy'], 1);
            add_filter(
                'plugin_action_links_' . plugin_basename(AASPF_PLUGIN_FILE),
                [$this, 'addSettingsLink']
            );
        }
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'podcast-forge',
            false,
            dirname(plugin_basename(AASPF_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * @param string[] $links
     *
     * @return string[]
     */
    public function addSettingsLink(array $links): array
    {
        $url = \PodcastForge\Admin\Urls::settings();

        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'podcast-forge') . '</a>'
        );

        return $links;
    }
}
