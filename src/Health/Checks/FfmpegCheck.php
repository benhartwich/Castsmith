<?php
declare(strict_types=1);

namespace PodcastForge\Health\Checks;

use PodcastForge\Health\CheckInterface;
use PodcastForge\Health\Result;
use PodcastForge\Settings\Options;
use PodcastForge\Support\ProcessRunner;

/**
 * Checks ffmpeg and ffprobe.
 *
 * Both are required: ffmpeg for assembling the segments, ffprobe for the
 * measured segment duration from which the chapter times are derived. A check
 * that only finds ffmpeg would hide the second failure until the audio
 * assembly actually runs.
 *
 * The call goes through ProcessRunner because many PHP-FPM setups block
 * `proc_open` and only allow `exec` — which does not apply on the command
 * line. A check that only knows `proc_open` would be green on the console
 * and red in the admin backend.
 */
final class FfmpegCheck implements CheckInterface
{
    public function id(): string
    {
        return 'ffmpeg';
    }

    public function label(): string
    {
        return 'ffmpeg';
    }

    public function run(): Result
    {
        // Not needed when the assembly runs in PHP on purpose or by fallback.
        if (\PodcastForge\Audio\AudioEngine::mode() === \PodcastForge\Audio\AudioEngine::MODE_PHP) {
            return Result::skip(__('Not needed: the assembly runs in PHP.', 'podcast-forge'));
        }

        if (!ProcessRunner::isAvailable()) {
            return Result::fail(
                __('External programs cannot be called.', 'podcast-forge'),
                __('Neither proc_open nor exec is available. Both are blocked in disable_functions.', 'podcast-forge')
            );
        }

        $ffmpeg  = Options::get('ffmpeg_path') !== '' ? Options::get('ffmpeg_path') : 'ffmpeg';
        $ffprobe = Options::get('ffprobe_path') !== '' ? Options::get('ffprobe_path') : 'ffprobe';

        $ffmpegVersion  = $this->version($ffmpeg);
        $ffprobeVersion = $this->version($ffprobe);

        if ($ffmpegVersion === null && $ffprobeVersion === null) {
            return Result::fail(
                __('Neither ffmpeg nor ffprobe can be called.', 'podcast-forge'),
                /* translators: 1: configured ffmpeg path or command, 2: configured ffprobe path or command */
                sprintf(__('Looked for "%1$s" and "%2$s".', 'podcast-forge'), $ffmpeg, $ffprobe)
            );
        }

        if ($ffmpegVersion === null) {
            /* translators: %s: configured ffmpeg path or command */
            return Result::fail(sprintf(__('ffmpeg cannot be called ("%s").', 'podcast-forge'), $ffmpeg));
        }

        if ($ffprobeVersion === null) {
            return Result::warn(
                /* translators: %s: configured ffprobe path or command */
                sprintf(__('ffmpeg is available, but ffprobe cannot be called ("%s").', 'podcast-forge'), $ffprobe),
                __('Without ffprobe, the segment durations and therefore the chapter times could not be measured.', 'podcast-forge')
            );
        }

        return Result::ok(
            /* translators: %s: ffmpeg version string */
            sprintf(__('Available (%s).', 'podcast-forge'), $ffmpegVersion),
            sprintf('ffprobe: %s', $ffprobeVersion)
        );
    }

    /**
     * @return string|null Version string, or null if the program does not run.
     */
    private function version(string $binary): ?string
    {
        $result = ProcessRunner::run([$binary, '-version']);

        if (!$result['ok']) {
            return null;
        }

        $firstLine = strtok($result['output'], "\n");
        if ($firstLine === false) {
            return null;
        }

        if (preg_match('/version\s+(\S+)/', $firstLine, $m) === 1) {
            return $m[1];
        }

        return trim($firstLine);
    }
}
