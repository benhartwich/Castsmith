<?php
declare(strict_types=1);

namespace PodcastForge\Health\Checks;

use PodcastForge\Audio\AudioEngine;
use PodcastForge\Audio\MusicBed;
use PodcastForge\Health\CheckInterface;
use PodcastForge\Health\Result;
use PodcastForge\Settings\Options;

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
        return __('Assembly', 'podcast-forge');
    }

    public function run(): Result
    {
        $reason = AudioEngine::reason();

        if (AudioEngine::mode() === AudioEngine::MODE_FFMPEG) {
            return AudioEngine::ffmpegAvailable()
                ? Result::ok(__('ffmpeg', 'podcast-forge'), $reason)
                : Result::fail(__('ffmpeg is set but not available.', 'podcast-forge'), $reason);
        }

        $music = MusicBed::active();
        $wantsMusic = $music['opener'] !== null || $music['outro'] !== null || $music['trenner'] !== [];
        if ($wantsMusic && Options::get('auphonic_preset') === '') {
            return Result::warn(
                __('PHP — but opener, bridges and outro need Auphonic, and no Auphonic preset is set.', 'podcast-forge'),
                $reason
            );
        }

        return Result::ok(__('PHP, music via Auphonic', 'podcast-forge'), $reason);
    }
}
