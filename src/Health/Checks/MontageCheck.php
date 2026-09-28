<?php
declare(strict_types=1);

namespace Castsmith\Health\Checks;

use Castsmith\Audio\AudioEngine;
use Castsmith\Audio\MusicBed;
use Castsmith\Health\CheckInterface;
use Castsmith\Health\Result;
use Castsmith\Settings\Options;

/**
 * Which assembly path is active, and whether it can do what is asked of it.
 */
final class MontageCheck implements CheckInterface
{
    public function id(): string
    {
        return 'montage';
    }

    public function label(): string
    {
        return __('Assembly', 'castsmith');
    }

    public function run(): Result
    {
        $reason = AudioEngine::reason();

        if (AudioEngine::mode() === AudioEngine::MODE_FFMPEG) {
            return AudioEngine::ffmpegAvailable()
                ? Result::ok(__('ffmpeg', 'castsmith'), $reason)
                : Result::fail(__('ffmpeg is set but not available.', 'castsmith'), $reason);
        }

        $music = MusicBed::active();
        $wantsMusic = $music['opener'] !== null || $music['outro'] !== null || $music['trenner'] !== [];
        if ($wantsMusic && Options::get('auphonic_preset') === '') {
            return Result::warn(
                __('PHP — but opener, bridges and outro need Auphonic, and no Auphonic preset is set.', 'castsmith'),
                $reason
            );
        }

        return Result::ok(__('PHP, music via Auphonic', 'castsmith'), $reason);
    }
}
