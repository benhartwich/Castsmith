<?php
declare(strict_types=1);

namespace PodcastForge\Health\Checks;

use PodcastForge\Health\CheckInterface;
use PodcastForge\Health\Result;
use PodcastForge\Storage\EpisodeStorage;

/**
 * Checks the storage location for the segment audio files.
 *
 * Two questions, and the second one is the reason the storage lives outside
 * of uploads in the first place: Is it writable, and is it outside of what
 * the web server serves? On this installation nginx serves everything under
 * `wp-content/uploads/` directly, and `.htaccess` has no effect there —
 * verified with a test file.
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

        if (EpisodeStorage::isInsideDocroot()) {
            return Result::warn(
                __('Writable, but located inside the served directory.', 'podcast-forge'),
                $detail . __(' Under Apache the generated .htaccess files protect it, under nginx they do not: there the raw recordings would be publicly accessible. Configure a path outside the web directory or block the folder in the web server.', 'podcast-forge')
            );
        }

        return Result::ok(__('Writable and outside the web directory.', 'podcast-forge'), $detail);
    }
}
