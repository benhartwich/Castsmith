<?php
declare(strict_types=1);

namespace PodcastForge\Voice;

use PodcastForge\Settings\Options;

/**
 * The voice settings of an episode.
 *
 * Configured once in the plugin, then copied and stored per episode.
 * The reason is reproducibility — if someone changes the setting, episodes
 * that have already been generated should remain unaffected.
 */
final class VoiceSettings
{
    public function __construct(
        public readonly float $stability = 0.5,
        public readonly float $similarityBoost = 0.75,
        public readonly float $style = 0.0,
        public readonly bool $useSpeakerBoost = true,
        public readonly float $speed = 1.0
    ) {
    }

    public static function fromOptions(): self
    {
        return new self(
            self::clamp((float) (Options::get('voice_stability') !== '' ? Options::get('voice_stability') : '0.5'), 0.0, 1.0),
            self::clamp((float) (Options::get('voice_similarity') !== '' ? Options::get('voice_similarity') : '0.75'), 0.0, 1.0),
            self::clamp((float) (Options::get('voice_style') !== '' ? Options::get('voice_style') : '0'), 0.0, 1.0),
            Options::get('voice_speaker_boost') !== '0',
            self::clamp((float) (Options::get('voice_speed') !== '' ? Options::get('voice_speed') : '1.0'), 0.7, 1.2)
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) ($data['stability'] ?? 0.5),
            (float) ($data['similarity_boost'] ?? 0.75),
            (float) ($data['style'] ?? 0.0),
            (bool) ($data['use_speaker_boost'] ?? true),
            (float) ($data['speed'] ?? 1.0)
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'stability'         => $this->stability,
            'similarity_boost'  => $this->similarityBoost,
            'style'             => $this->style,
            'use_speaker_boost' => $this->useSpeakerBoost,
            'speed'             => $this->speed,
        ];
    }

    /**
     * Short form for the segment hash. If a setting changes, the voice sounds
     * different, and all segments have to be regenerated.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', (string) wp_json_encode($this->toArray())), 0, 16);
    }

    private static function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
