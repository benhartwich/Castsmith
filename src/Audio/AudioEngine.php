<?php
declare(strict_types=1);

namespace Sonoquill\Audio;

use Sonoquill\Settings\Options;
use Sonoquill\Support\ProcessRunner;

/**
 * Which assembly path is in use: ffmpeg on the server, or plain PHP with
 * Auphonic doing the music.
 *
 * - ffmpeg: full control — room tone in the pauses, loudness matching of
 *   patched segments, music mixed like a radio feature (bridges fade under
 *   the next chapter).
 * - PHP: segments are joined frame by frame (Mp3), pauses are silent frames;
 *   opener, bridges and outro are added by Auphonic (intro with ducking,
 *   inserts, outro). Works on hosting without ffmpeg or exec().
 *
 * Setting `montage_mode`: auto (default), ffmpeg, php.
 */
final class AudioEngine
{
    public const MODE_FFMPEG = 'ffmpeg';
    public const MODE_PHP = 'php';

    private const CACHE = 'aaspf_ffmpeg_available';

    private static ?bool $available = null;

    public static function mode(): string
    {
        return match (Options::get('montage_mode')) {
            self::MODE_FFMPEG => self::MODE_FFMPEG,
            self::MODE_PHP    => self::MODE_PHP,
            default           => self::ffmpegAvailable() ? self::MODE_FFMPEG : self::MODE_PHP,
        };
    }

    /**
     * One sentence on why this path is active, for settings and health check.
     */
    public static function reason(): string
    {
        $setting = Options::get('montage_mode');
        if ($setting === self::MODE_FFMPEG) {
            return self::ffmpegAvailable()
                ? __('ffmpeg, as set.', 'sonoquill')
                : __('ffmpeg is set, but cannot be called — assembly will fail.', 'sonoquill');
        }
        if ($setting === self::MODE_PHP) {
            return __('PHP with Auphonic, as set.', 'sonoquill');
        }

        return self::ffmpegAvailable()
            ? __('Automatic: ffmpeg is available.', 'sonoquill')
            : __('Automatic: ffmpeg is not available here, so segments are joined in PHP and Auphonic adds the music.', 'sonoquill');
    }

    /**
     * Can ffmpeg be run? Checked at most once an hour.
     */
    public static function ffmpegAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $cached = function_exists('get_transient') ? get_transient(self::CACHE) : false;
        if ($cached === 'yes' || $cached === 'no') {
            return self::$available = ($cached === 'yes');
        }

        $ok = false;
        if (ProcessRunner::isAvailable()) {
            $result = ProcessRunner::run([Ffmpeg::ffmpegPath(), '-hide_banner', '-version']);
            $ok = $result['ok'] && str_contains($result['output'] . $result['error'], 'ffmpeg');
        }

        if (function_exists('set_transient')) {
            set_transient(self::CACHE, $ok ? 'yes' : 'no', HOUR_IN_SECONDS);
        }

        return self::$available = $ok;
    }

    /** After changing paths or the server: check again. */
    public static function forget(): void
    {
        self::$available = null;
        if (function_exists('delete_transient')) {
            delete_transient(self::CACHE);
        }
    }

    /**
     * Duration of an audio file in milliseconds: ffmpeg where available,
     * else counted from the MP3 frames.
     */
    public static function durationMs(string $path): ?int
    {
        if (self::mode() === self::MODE_FFMPEG) {
            $ms = Ffmpeg::durationMs($path);
            if ($ms !== null) {
                return $ms;
            }
        }

        return Mp3::durationMs($path);
    }
}
