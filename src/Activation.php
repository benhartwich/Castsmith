<?php
declare(strict_types=1);

namespace PodcastForge;

use PodcastForge\Db\Schema;
use PodcastForge\Jobs\Scheduler;
use PodcastForge\Settings\Options;

/**
 * What happens when the plugin is activated and deactivated.
 *
 * Deliberately not included: creating a directory for segment audio files.
 * Under nginx, uploads/ is publicly readable and .htaccess has no effect.
 * EpisodeStorage chooses the directory when it is first needed, and the
 * storage health check tells whether it is reachable from the web.
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
    }
}
