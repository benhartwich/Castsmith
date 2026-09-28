<?php
declare(strict_types=1);

namespace Castsmith;

use Castsmith\Db\Schema;
use Castsmith\Admin\EpisodeActions;
use Castsmith\Admin\AudioStream;
use Castsmith\Admin\DictionaryController;
use Castsmith\Admin\EpisodesPage;
use Castsmith\Admin\PatchController;
use Castsmith\Health\AjaxController;
use Castsmith\Jobs\AdminActions;
use Castsmith\Jobs\Scheduler;
use Castsmith\Pipeline\Scheduler as PipelineScheduler;
use Castsmith\Settings\SettingsPage;

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
        \Castsmith\Rest\AuphonicWebhook::register();
        // Translations everywhere, not only in the admin: mails and run-log
        // entries are written by background jobs, too.
        add_action('init', [$this, 'loadTextdomain']);
        // Schema updates on every request, not only in the admin: after an
        // update a background job may run before anyone opens the backend.
        add_action('init', [Schema::class, 'maybeInstall'], 1);
        // Changed paths or mode: check for ffmpeg again.
        add_action('update_option_' . \Castsmith\Settings\Options::OPTION, [\Castsmith\Audio\AudioEngine::class, 'forget'], 10, 0);
        // Publishing also happens from within the Podlove backend, so this is not admin-only.
        \Castsmith\Notify\Announcement::register();

        // The plugin has no frontend output; the screens are admin-only.
        if (is_admin()) {
            SettingsPage::register();
            EpisodesPage::register();
            \Castsmith\Admin\PromptsPage::register();
            EpisodeActions::register();
            AudioStream::register();
            PatchController::register();
            DictionaryController::register();
            AjaxController::register();
            AdminActions::register();
            \Castsmith\Admin\ProgressController::register();
            add_action('admin_init', [\Castsmith\Admin\Urls::class, 'redirectLegacy'], 1);
            add_filter(
                'plugin_action_links_' . plugin_basename(AASPF_PLUGIN_FILE),
                [$this, 'addSettingsLink']
            );
        }
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'castsmith',
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
        $url = \Castsmith\Admin\Urls::settings();

        array_unshift(
            $links,
            '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'castsmith') . '</a>'
        );

        return $links;
    }
}
