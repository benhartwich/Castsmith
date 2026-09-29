<?php
declare(strict_types=1);

namespace Sonoquill;

use Sonoquill\Db\Schema;
use Sonoquill\Jobs\Scheduler;
use Sonoquill\Settings\Options;

/**
 * What happens when the plugin is activated and deactivated.
 *
 * Activation only creates the tables. The storage directory is created when
 * it is first needed (see EpisodeStorage), and the storage health check
 * tells whether it is reachable from the web.
 */
final class Activation
{
    public static function activate(): void
    {
        Schema::install();
        Options::ensureExists();
    }

    public static function deactivate(): void
    {
        Scheduler::unscheduleAll();

        // The bundled Action Scheduler runs its queue from a WP-Cron event
        // with its own one-minute schedule. Once this plugin is gone, the
        // schedule is gone too and WP-Cron would log an error on every run.
        // Other plugins that use Action Scheduler add the event again on
        // their next request.
        wp_clear_scheduled_hook('action_scheduler_run_queue', ['WP Cron']);
    }
}
