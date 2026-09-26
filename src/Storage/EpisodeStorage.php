<?php
declare(strict_types=1);

namespace PodcastForge\Storage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking the plugin's own storage directory, also from background jobs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own episode directory.
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing files in the plugin's own storage directory.

use PodcastForge\Settings\Options;

/**
 * Storage for the segment audio files, above the document root.
 *
 * Under nginx everything inside the WordPress installation is served,
 * including `uploads/`, and `.htaccess` has no effect there. The raw recordings
 * must not be public. Therefore, in this order:
 *
 * 1. the configured path (setting "Storage"),
 * 2. `podcast-forge-data` one level above the WordPress installation, if it
 *    exists or can be created — outside of what the web server serves,
 * 3. otherwise `wp-content/podcast-forge-data-<random>` with protection files.
 *    These only work under Apache; the random name part, generated once,
 *    ensures that nobody can guess the folder. The health check still warns.
 *
 * The database stores relative paths. Moving the root directory therefore
 * does not invalidate any row.
 */
final class EpisodeStorage
{
    public static function baseDir(): string
    {
        $configured = trim(Options::get('storage_dir'));
        if ($configured !== '') {
            return untrailingslashit($configured);
        }

        $parent = dirname(untrailingslashit(ABSPATH));
        $outside = $parent . '/podcast-forge-data';
        if (is_dir($outside) || (is_dir($parent) && is_writable($parent))) {
            return $outside;
        }

        $suffix = (string) get_option('aaspf_storage_suffix', '');
        if ($suffix === '') {
            $suffix = strtolower(wp_generate_password(16, false));
            add_option('aaspf_storage_suffix', $suffix, '', false);
        }

        return untrailingslashit(WP_CONTENT_DIR) . '/podcast-forge-data-' . $suffix;
    }

    public static function episodeDir(int $episodeId): string
    {
        return self::baseDir() . '/' . $episodeId;
    }

    /**
     * Creates the directory and additionally secures it.
     *
     * The protection files have no effect under nginx, but they cost nothing
     * and take effect if the storage ever moves to Apache or into the
     * document root.
     *
     * @throws \RuntimeException
     */
    public static function ensureEpisodeDir(int $episodeId): string
    {
        $dir = self::episodeDir($episodeId);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            /* translators: %s: path of the episode directory */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'podcast-forge'), $dir));
        }

        $base = self::baseDir();
        foreach ([$base . '/index.php' => "<?php\n// Nichts zu sehen.\n", $base . '/.htaccess' => "Require all denied\n"] as $file => $content) {
            if (!file_exists($file)) {
                @file_put_contents($file, $content);
            }
        }

        return $dir;
    }

    public static function segmentRelativePath(int $episodeId, int $index, string $extension = 'mp3'): string
    {
        return sprintf('%d/segment-%03d.%s', $episodeId, $index, $extension);
    }

    public static function mixRelativePath(int $episodeId, string $extension = 'mp3'): string
    {
        return sprintf('%d/folge.%s', $episodeId, $extension);
    }

    public static function absolutePath(string $relative): string
    {
        return self::baseDir() . '/' . ltrim($relative, '/');
    }

    public static function exists(string $relative): bool
    {
        return $relative !== '' && is_readable(self::absolutePath($relative));
    }

    public static function size(string $relative): int
    {
        $path = self::absolutePath($relative);

        return is_readable($path) ? (int) filesize($path) : 0;
    }

    /**
     * @throws \RuntimeException
     */
    public static function write(string $relative, string $bytes): void
    {
        $path = self::absolutePath($relative);
        $dir = dirname($path);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            /* translators: %s: path of the directory */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'podcast-forge'), $dir));
        }

        if (file_put_contents($path, $bytes) === false) {
            /* translators: %s: absolute path of the file */
            throw new \RuntimeException(sprintf(__('File could not be written: %s', 'podcast-forge'), $path));
        }
    }

    /**
     * Size of all files of an episode.
     */
    public static function sizeOfEpisode(int $episodeId): int
    {
        $total = 0;
        foreach ((array) glob(self::episodeDir($episodeId) . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                $total += (int) filesize($file);
            }
        }

        return $total;
    }

    /**
     * Removes only the raw segment recordings.
     *
     * They can be cleaned up after successful publication, but not
     * automatically — you want to still have them when the next error occurs.
     * The finished episode and the transcript are therefore kept.
     */
    public static function deleteSegments(int $episodeId): int
    {
        $removed = 0;
        foreach ((array) glob(self::episodeDir($episodeId) . '/segment-*') as $file) {
            if (is_string($file) && is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public static function deleteEpisode(int $episodeId): void
    {
        $dir = self::episodeDir($episodeId);
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($dir);
    }

    /**
     * @return array{writable:bool,path:string,message:string}
     */
    public static function status(): array
    {
        $base = self::baseDir();

        if (!is_dir($base)) {
            $parent = dirname($base);

            return [
                'writable' => is_writable($parent),
                'path'     => $base,
                'message'  => is_writable($parent)
                    ? __('Will be created on the first run.', 'podcast-forge')
                    /* translators: %s: path of the parent directory of the storage */
                    : sprintf(__('The parent directory %s is not writable.', 'podcast-forge'), $parent),
            ];
        }

        return [
            'writable' => is_writable($base),
            'path'     => $base,
            'message'  => is_writable($base) ? __('Present and writable.', 'podcast-forge') : __('Present, but not writable.', 'podcast-forge'),
        ];
    }

    /**
     * Is the storage located inside what the web server serves?
     *
     * This question is the whole reason for this class, so it is answered
     * rather than assumed.
     */
    public static function isInsideDocroot(): bool
    {
        $base = realpath(self::baseDir()) ?: self::baseDir();
        $root = realpath(untrailingslashit(ABSPATH)) ?: untrailingslashit(ABSPATH);

        return str_starts_with($base . '/', $root . '/');
    }
}
