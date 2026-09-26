<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler verifies its nonce first (guard() / check_ajax_referer()).

use PodcastForge\Audio\AlignmentScaler;
use PodcastForge\Audio\Ffmpeg;
use PodcastForge\Db\EpisodeRepository;
use PodcastForge\Segments\SegmentRepository;
use PodcastForge\Segments\SegmentStatus;
use PodcastForge\Settings\SettingsPage;
use PodcastForge\Storage\EpisodeStorage;
use PodcastForge\Voice\VoiceChanger;

/**
 * Receives the passage recorded in the browser and replaces the segment.
 *
 * For the user this is a single action: mark, speak it, done. The fact that
 * a voice changer call and a file swap happen behind the scenes stays
 * invisible — that is the intended experience.
 */
final class PatchController
{
    public const ACTION = 'aaspf_patch_segment';
    public const NONCE  = 'aaspf_patch';

    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
    }

    public static function handle(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_send_json_error(['message' => __('You do not have permission to do this.', 'podcast-forge')], 403);
        }

        check_ajax_referer(self::NONCE, 'nonce');

        $segmentId = isset($_POST['segment']) ? (int) $_POST['segment'] : 0;
        $segment = SegmentRepository::find($segmentId);

        if ($segment === null) {
            wp_send_json_error(['message' => __('This segment does not exist.', 'podcast-forge')], 404);
        }

        $recording = self::readUpload();
        if ($recording === null) {
            wp_send_json_error(['message' => __('No recording was received.', 'podcast-forge')], 400);
        }

        $episodeId = (int) $segment['episode_id'];

        try {
            $changer = VoiceChanger::fromSettings();
            $audio = $changer->convert($recording['bytes'], $recording['filename'], (int) $segment['seed']);

            $relative = EpisodeStorage::segmentRelativePath($episodeId, (int) $segment['idx'], $changer->fileExtension());
            EpisodeStorage::write($relative, $audio);

            $duration = \PodcastForge\Audio\AudioEngine::durationMs(EpisodeStorage::absolutePath($relative));
            $previous = (int) $segment['duration_ms'];

            SegmentRepository::update($segmentId, [
                'audio_path'     => $relative,
                'source'         => 'sts',
                'duration_ms'    => $duration,
                'status'         => SegmentStatus::PATCHED,
                'note'           => '',
                'alignment_json' => (string) wp_json_encode(
                    AlignmentScaler::scale(
                        EpisodeRepository::decodeMap($segment['alignment_json'] ?? null),
                        $previous,
                        $duration ?? $previous
                    )
                ),
            ]);

            // This makes the mixdown outdated.
            EpisodeRepository::update($episodeId, ['mixed_audio_path' => '']);

            EpisodeRepository::log($episodeId, 'nachsprechen', sprintf(
                /* translators: 1: segment number, 2: previous duration in seconds, 3: new duration in seconds */
                __('Segment %1$d re-recorded. Before %2$s, now %3$s.', 'podcast-forge'),
                (int) $segment['idx'],
                self::seconds($previous),
                self::seconds((int) $duration)
            ));

            wp_send_json_success([
                'message'  => __('Re-recorded. The mixdown has to be rendered again.', 'podcast-forge'),
                'duration' => self::seconds((int) $duration),
                'audioUrl' => AudioStream::segmentUrl($segmentId) . '&t=' . time(),
            ]);
        } catch (\Throwable $e) {
            EpisodeRepository::log($episodeId, 'nachsprechen', sprintf(
                /* translators: 1: segment number, 2: error message */
                __('Segment %1$d failed: %2$s', 'podcast-forge'),
                (int) $segment['idx'],
                $e->getMessage()
            ));

            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array{bytes:string,filename:string}|null
     */
    private static function readUpload(): ?array
    {
        if (!isset($_FILES['recording']) || !is_array($_FILES['recording'])) {
            return null;
        }

        $file = $_FILES['recording']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- temporary upload, type and size are checked below

        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return null;
        }

        if ((int) ($file['size'] ?? 0) > VoiceChanger::MAX_UPLOAD_BYTES) {
            return null;
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        // Depending on the device, the browser delivers webm, ogg or mp4.
        // ElevenLabs detects the format itself; the extension is only a hint.
        $name = sanitize_file_name((string) ($file['name'] ?? 'aufnahme.webm'));
        if ($name === '' || !str_contains($name, '.')) {
            $name = 'aufnahme.webm';
        }

        return ['bytes' => $bytes, 'filename' => $name];
    }

    private static function seconds(int $ms): string
    {
        return number_format_i18n($ms / 1000, 1) . ' s';
    }
}
