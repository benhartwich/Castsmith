<?php
declare(strict_types=1);

namespace PodcastForge\Podlove;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

/**
 * Stores the publication-ready files where Podlove expects them.
 *
 * This is deliberately a different location from the raw segment recordings:
 * the finished episode and its transcript must be publicly accessible, since
 * they are listed in the feed. Podlove builds the address from its base URL
 * and the episode's slug, which is why the slug determines the file name.
 */
final class MediaStore
{
    /**
     * Where the finished files go, and under which URL Podlove serves them.
     *
     * Podlove builds a media URL from its "media file base URL", the episode
     * slug, the asset's suffix and the extension. If that base URL points into
     * this site's uploads directory, the files are written exactly there;
     * otherwise into uploads/podcasts, and the Podlove health check says which
     * base URL to set.
     *
     * @return array{dir:string,url:string,matches_podlove:bool}
     */
    public static function location(): array
    {
        $uploads = wp_upload_dir();
        $baseDir = untrailingslashit((string) $uploads['basedir']);
        $baseUrl = untrailingslashit((string) $uploads['baseurl']);

        $podlove = get_option('podlove_podcast');
        $mediaUrl = is_array($podlove) ? untrailingslashit(trim((string) ($podlove['media_file_base_uri'] ?? ''))) : '';

        // Compare without scheme: the site may run behind https while the option says http, or vice versa.
        $strip = static fn (string $u): string => (string) preg_replace('#^https?://#i', '', $u);
        if ($mediaUrl !== '' && str_starts_with($strip($mediaUrl) . '/', $strip($baseUrl) . '/')) {
            $relative = ltrim(substr($strip($mediaUrl), strlen($strip($baseUrl))), '/');

            return [
                'dir'             => $baseDir . ($relative !== '' ? '/' . $relative : ''),
                'url'             => $mediaUrl,
                'matches_podlove' => true,
            ];
        }

        return ['dir' => $baseDir . '/podcasts', 'url' => $baseUrl . '/podcasts', 'matches_podlove' => false];
    }

    public static function directory(): string
    {
        return self::location()['dir'];
    }

    /**
     * The Podlove episode asset for a file type (mp3, vtt): the first asset
     * whose file type has this extension. Filter `podcast_forge_podlove_asset`
     * can pick another one.
     *
     * @return array{id:int,suffix:string}|null
     */
    public static function asset(string $extension): ?array
    {
        $found = null;
        if (class_exists('\\Podlove\\Model\\EpisodeAsset')) {
            foreach ((array) \Podlove\Model\EpisodeAsset::all('ORDER BY position ASC') as $asset) {
                $type = method_exists($asset, 'file_type') ? $asset->file_type() : null;
                if ($type !== null && strtolower((string) $type->extension) === strtolower($extension)) {
                    $found = ['id' => (int) $asset->id, 'suffix' => (string) ($asset->suffix ?? '')];
                    break;
                }
            }
        }

        /** Filter: the Podlove episode asset ([id, suffix]) used for a file extension, or null. */
        $filtered = apply_filters('podcast_forge_podlove_asset', $found, $extension);

        return is_array($filtered) && isset($filtered['id']) ? ['id' => (int) $filtered['id'], 'suffix' => (string) ($filtered['suffix'] ?? '')] : null;
    }

    public static function path(string $slug, string $extension): string
    {
        return self::directory() . '/' . self::filename($slug, $extension);
    }

    public static function url(string $slug, string $extension): string
    {
        return self::location()['url'] . '/' . self::filename($slug, $extension);
    }

    private static function filename(string $slug, string $extension): string
    {
        $extension = ltrim($extension, '.');
        $asset = self::asset($extension);

        return $slug . ($asset['suffix'] ?? '') . '.' . $extension;
    }

    /**
     * @throws \RuntimeException
     */
    public static function write(string $slug, string $extension, string $bytes): string
    {
        $directory = self::directory();

        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            /* translators: %s: path of the podcast upload directory */
            throw new \RuntimeException(sprintf(__('Directory could not be created: %s', 'podcast-forge'), $directory));
        }

        $path = self::path($slug, $extension);

        if (file_put_contents($path, $bytes) === false) {
            /* translators: %s: path of the media file being written */
            throw new \RuntimeException(sprintf(__('File could not be written: %s', 'podcast-forge'), $path));
        }

        return $path;
    }
}
