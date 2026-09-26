<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

/**
 * Version string for stylesheets and scripts.
 *
 * The server delivers static files with a thirty-day cache. Using the plugin
 * version as the identifier (which never changed), a browser kept showing the
 * old stylesheet after the rework of 25.09.2026 — the episode view appeared
 * unstyled. The file's modification time changes with every edit.
 */
final class Assets
{
    public static function version(string $relative): string
    {
        $mtime = @filemtime(AASPF_PLUGIN_DIR . $relative);

        return $mtime !== false ? AASPF_VERSION . '.' . $mtime : AASPF_VERSION;
    }
}
