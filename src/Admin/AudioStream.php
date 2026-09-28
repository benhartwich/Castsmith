<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters of admin screens.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming and appending large audio files; WP_Filesystem would hold them in memory and is not set up in background jobs.

use PodcastForge\Db\EpisodeRepository;
use PodcastForge\Segments\SegmentRepository;
use PodcastForge\Settings\SettingsPage;
use PodcastForge\Storage\EpisodeStorage;

/**
 * Serves segment and episode audio to the admin area.
 *
 * The files are deliberately stored outside of what the web server serves.
 * Listening to them therefore requires this route — with a capability check,
 * a nonce and a path that never comes from the request but always from the
 * database.
 */
final class AudioStream
{
    public const ACTION = 'aaspf_audio';
    public const NONCE  = 'aaspf_audio';

    public static function register(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    public static function segmentUrl(int $segmentId): string
    {
        return add_query_arg([
            'action'   => self::ACTION,
            'segment'  => $segmentId,
            '_wpnonce' => wp_create_nonce(self::NONCE),
        ], admin_url('admin-post.php'));
    }

    public static function episodeUrl(int $episodeId): string
    {
        return add_query_arg([
            'action'   => self::ACTION,
            'episode'  => $episodeId,
            '_wpnonce' => wp_create_nonce(self::NONCE),
        ], admin_url('admin-post.php'));
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'podcast-forge'), '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

        $relative = self::resolvePath();
        if ($relative === null) {
            wp_die(esc_html__('This file does not exist.', 'podcast-forge'), '', ['response' => 404]);
        }

        $path = EpisodeStorage::absolutePath($relative);

        // Belt and braces: the resolved path must lie below the storage root
        // directory, even though it comes from the database and not from the
        // request.
        $real = realpath($path);
        $base = realpath(EpisodeStorage::baseDir());

        if ($real === false || $base === false || !str_starts_with($real, $base . '/')) {
            wp_die(esc_html__('This file does not exist.', 'podcast-forge'), '', ['response' => 404]);
        }

        self::stream($real);
    }

    private static function resolvePath(): ?string
    {
        $segmentId = isset($_GET['segment']) ? (int) $_GET['segment'] : 0;
        if ($segmentId > 0) {
            $segment = SegmentRepository::find($segmentId);
            $relative = $segment === null ? '' : (string) $segment['audio_path'];

            return $relative !== '' && EpisodeStorage::exists($relative) ? $relative : null;
        }

        $episodeId = isset($_GET['episode']) ? (int) $_GET['episode'] : 0;
        if ($episodeId > 0) {
            $episode = EpisodeRepository::find($episodeId);
            $relative = $episode === null ? '' : (string) $episode['mixed_audio_path'];

            return $relative !== '' && EpisodeStorage::exists($relative) ? $relative : null;
        }

        return null;
    }

    /**
     * Delivery with range requests, so that seeking works in the player.
     */
    private static function stream(string $path): void
    {
        $size = (int) filesize($path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'wav' => 'audio/wav',
            'vtt' => 'text/vtt',
            default => 'audio/mpeg',
        };

        $start = 0;
        $end = $size - 1;
        $partial = false;

        $range = isset($_SERVER['HTTP_RANGE']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_RANGE'])) : '';
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m) === 1) {
            $partial = true;
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }
            if ($start > $end) {
                header('HTTP/1.1 416 Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
        }

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('X-Content-Type-Options: nosniff');

        if ($partial) {
            header('HTTP/1.1 206 Partial Content');
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            exit;
        }

        // Binary audio, copied straight to the response.
        $output = fopen('php://output', 'wb');
        if ($output !== false) {
            stream_copy_to_stream($handle, $output, $end - $start + 1, $start);
            fclose($output);
        }

        fclose($handle);
        exit;
    }
}
