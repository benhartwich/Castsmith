<?php
declare(strict_types=1);

namespace PodcastForge\Health\Checks;

use PodcastForge\Health\CheckInterface;
use PodcastForge\Health\Result;
use PodcastForge\Storage\EpisodeStorage;

/**
 * Checks the storage location for the segment audio files.
 *
 * Two questions: Is it writable, and can the raw recordings be fetched from
 * the web? The second is answered, not assumed: a probe file is written into
 * the storage folder and requested over HTTP. Apache honours the .htaccess
 * in the folder, nginx does not.
 */
final class StorageCheck implements CheckInterface
{
    public function id(): string
    {
        return 'ablage';
    }

    public function label(): string
    {
        return __('Audio storage', 'podcast-forge');
    }

    public function run(): Result
    {
        $status = EpisodeStorage::status();
        /* translators: %s: filesystem path of the audio storage directory */
        $detail = sprintf(__('Path: %s', 'podcast-forge'), $status['path']);

        if (!$status['writable']) {
            return Result::fail($status['message'], $detail);
        }

        $url = EpisodeStorage::url();
        if ($url === null) {
            return EpisodeStorage::isInsideDocroot()
                ? Result::warn(__('Writable, but located inside the WordPress directory.', 'podcast-forge'), $detail . __(' Make sure the web server does not serve this folder.', 'podcast-forge'))
                : Result::ok(__('Writable and outside the web directory.', 'podcast-forge'), $detail);
        }

        $reachable = self::probe($url);
        if ($reachable === null) {
            return Result::warn(__('Writable; whether it is reachable from the web could not be tested.', 'podcast-forge'), $detail);
        }

        if ($reachable) {
            return Result::warn(
                __('Writable, but the files can be downloaded from the web.', 'podcast-forge'),
                $detail . ' ' . sprintf(
                    /* translators: 1: URL path of the storage folder, 2: name of the filter */
                    __('The web server ignores the .htaccess in this folder (nginx does). Block the path %1$s in the server configuration, or move the storage outside the web directory with the filter %2$s.', 'podcast-forge'),
                    (string) wp_parse_url($url, PHP_URL_PATH),
                    'podcast_forge_storage_dir'
                )
            );
        }

        return Result::ok(__('Writable and protected from direct access.', 'podcast-forge'), $detail);
    }

    /**
     * Writes a probe file and requests it. True: served, false: blocked,
     * null: could not be tested.
     */
    private static function probe(string $url): ?bool
    {
        try {
            EpisodeStorage::ensureBaseDir();
        } catch (\RuntimeException $e) {
            return null;
        }

        $name = 'probe-' . strtolower(wp_generate_password(12, false)) . '.txt';
        $content = wp_generate_password(24, false);
        $path = EpisodeStorage::baseDir() . '/' . $name;

        if (@file_put_contents($path, $content) === false) {
            return null;
        }

        $response = wp_remote_get($url . '/' . $name, ['timeout' => 10, 'redirection' => 0]);
        wp_delete_file($path);

        if (is_wp_error($response)) {
            return null;
        }

        return wp_remote_retrieve_response_code($response) === 200
            && trim(wp_remote_retrieve_body($response)) === $content;
    }
}
