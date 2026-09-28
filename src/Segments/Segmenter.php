<?php
declare(strict_types=1);

namespace Castsmith\Segments;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Castsmith\Db\EpisodeRepository;
use Castsmith\Voice\PronunciationDictionary;
use Castsmith\Voice\VoiceSettings;

/**
 * Segmentation: paragraphs become segments.
 *
 * Break tags mark the chapter boundaries and are removed from the spoken
 * text — the pause is created during assembly as real silence, because
 * break tags are unstable with Multilingual v2 and disturb the timing
 * calculation.
 *
 * Re-segmenting preserves whatever can be preserved: if a segment's text
 * stays the same, it keeps its seed and audio. That is the reason for the
 * whole segment structure — only regenerate what has changed.
 */
final class Segmenter
{
    /**
     * Longest paragraph that fits into a single segment. ElevenLabs accepts at
     * most 5000 characters per call; longer paragraphs are split at sentence ends.
     */
    public const MAX_SEGMENT_CHARS = 3000;

    /**
     * @return array{angelegt:int,erhalten:int,entfernt:int,gesamt:int}
     */
    public static function rebuild(int $episodeId): array
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            /* translators: %d: episode ID */
            throw new \RuntimeException(sprintf(__('Episode %d does not exist.', 'castsmith'), $episodeId));
        }

        $script = (string) ($episode['script_text'] ?? '');
        if (trim($script) === '') {
            throw new \RuntimeException(__('The episode has no spoken script.', 'castsmith'));
        }

        $chapters = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        $disclosure = (int) ($episode['ai_disclosure_in_audio'] ?? 0) === 1
            ? trim(\Castsmith\Settings\Options::get('ai_disclosure_audio_text'))
            : '';
        $planned = self::plan($script, $chapters, $disclosure);

        $voice = VoiceSettings::fromOptions();
        $modelId = self::modelId();
        $document = PronunciationDictionary::document();
        $rules = $document === null ? [] : $document->rules();

        $existing = [];
        foreach (SegmentRepository::forEpisode($episodeId) as $segment) {
            $existing[(int) $segment['idx']] = $segment;
        }

        $created = 0;
        $kept = 0;

        foreach ($planned as $index => $plan) {
            $previous = $existing[$index] ?? null;
            $seed = $previous !== null && $previous['seed'] !== null
                ? (int) $previous['seed']
                : random_int(0, 4294967295);

            $hash = SegmentHash::compute($plan['text'], $seed, $modelId, $voice, $rules);
            $fingerprint = SegmentHash::dictionaryFingerprint($plan['text'], $rules);

            $fields = [
                'episode_id'      => $episodeId,
                'idx'             => $index,
                'text'            => $plan['text'],
                'kind'            => $plan['kind'],
                'chapter_title'   => $plan['chapter_title'],
                'seed'            => $seed,
                'dict_fingerprint'=> $fingerprint,
                'hash'            => $hash,
            ];

            if ($previous === null) {
                SegmentRepository::insert($fields + [
                    'status' => SegmentStatus::PENDING,
                    'source' => 'tts',
                ]);
                $created++;
                continue;
            }

            // Unchanged hash: audio and duration stay as they are.
            if ((string) $previous['hash'] === $hash && (string) $previous['audio_path'] !== '') {
                SegmentRepository::update((int) $previous['id'], $fields);
                $kept++;
                continue;
            }

            SegmentRepository::update((int) $previous['id'], $fields + [
                'audio_path'   => '',
                'duration_ms'  => null,
                'status'       => SegmentStatus::PENDING,
                'source'       => 'tts',
            ]);
            $created++;
        }

        // Remove surplus segments left over from an earlier, longer script.
        $removed = 0;
        foreach ($existing as $index => $segment) {
            if ($index >= count($planned)) {
                SegmentRepository::update((int) $segment['id'], ['episode_id' => 0]);
                global $wpdb;
                $wpdb->delete(\Castsmith\Db\Schema::segmentsTable(), ['id' => (int) $segment['id']]);
                $removed++;
            }
        }

        EpisodeRepository::update($episodeId, [
            'voice_id'       => \Castsmith\Settings\Options::get('elevenlabs_voice_id'),
            'voice_settings' => (string) wp_json_encode($voice->toArray()),
            'tts_model_id'   => $modelId,
            'dict_id'        => \Castsmith\Settings\Options::get('elevenlabs_dictionary_id'),
            'dict_version_id'=> \Castsmith\Settings\Options::get('elevenlabs_dictionary_version_id'),
        ]);

        return [
            'angelegt'  => $created,
            'erhalten'  => $kept,
            'entfernt'  => $removed,
            'gesamt'    => count($planned),
        ];
    }

    /**
     * Splits the script without touching the database.
     *
     * With $disclosure, the spoken AI notice is added as a separate segment:
     * before the sign-off, otherwise at the end. It is not part of the spoken
     * script, so it affects neither the approval nor the number check.
     *
     * @param list<array<string,mixed>> $chapters
     *
     * @return list<array{text:string,kind:string,chapter_title:string}>
     */
    public static function plan(string $script, array $chapters = [], string $disclosure = ''): array
    {
        // The script arrives from the browser's text field with \r\n; without
        // this normalisation, paragraphs() would no longer find blank lines.
        $script = str_replace(["\r\n", "\r"], "\n", $script);
        $sections = preg_split('/<break\b[^>]*>/iu', $script) ?: [];
        $planned = [];

        $sectionNumber = 0;
        foreach ($sections as $section) {
            $paragraphs = self::paragraphs($section);
            if ($paragraphs === []) {
                continue;
            }

            $sectionNumber++;
            $title = self::chapterTitle($chapters, $sectionNumber);

            foreach ($paragraphs as $position => $paragraph) {
                $isSectionStart = $position === 0;

                $planned[] = [
                    'text'          => $paragraph,
                    'kind'          => $isSectionStart
                        ? ($sectionNumber === 1 ? SegmentKind::INTRO : SegmentKind::CHAPTER_START)
                        : SegmentKind::BODY,
                    'chapter_title' => $isSectionStart ? $title : '',
                ];
            }
        }

        if ($planned !== []) {
            $last = count($planned) - 1;
            // The last segment is the sign-off, as long as it is not also
            // the start of a chapter.
            if ($planned[$last]['kind'] === SegmentKind::BODY) {
                $planned[$last]['kind'] = SegmentKind::OUTRO;
            }

            $disclosure = trim($disclosure);
            if ($disclosure !== '') {
                $segment = ['text' => $disclosure, 'kind' => SegmentKind::DISCLOSURE, 'chapter_title' => ''];
                if ($planned[$last]['kind'] === SegmentKind::OUTRO) {
                    array_splice($planned, $last, 0, [$segment]);
                } else {
                    $planned[] = $segment;
                }
            }
        }

        return $planned;
    }

    /**
     * @return list<string>
     */
    private static function paragraphs(string $section): array
    {
        $chunks = preg_split('/\n[ \t]*\n+/u', trim($section)) ?: [];
        $paragraphs = [];

        foreach ($chunks as $chunk) {
            $text = trim((string) preg_replace('/\s*\n\s*/u', ' ', $chunk));
            if ($text !== '') {
                array_push($paragraphs, ...self::limit($text));
            }
        }

        return $paragraphs;
    }

    /**
     * Splits an overly long paragraph at sentence ends into pieces of roughly equal size.
     *
     * @return list<string>
     */
    public static function limit(string $text, int $max = self::MAX_SEGMENT_CHARS): array
    {
        $length = mb_strlen($text);
        if ($length <= $max) {
            return [$text];
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text) ?: [$text];
        $target = (int) ceil($length / (int) ceil($length / $max));

        $pieces = [];
        $current = '';
        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;
            if ($current !== '' && mb_strlen($candidate) > $target) {
                $pieces[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * @param list<array<string,mixed>> $chapters
     */
    private static function chapterTitle(array $chapters, int $sectionNumber): string
    {
        foreach ($chapters as $chapter) {
            if ((int) ($chapter['abschnitt'] ?? 0) === $sectionNumber) {
                return (string) ($chapter['titel'] ?? '');
            }
        }

        return '';
    }

    private static function modelId(): string
    {
        $model = \Castsmith\Settings\Options::get('elevenlabs_model_id');

        return $model !== '' ? $model : 'eleven_multilingual_v2';
    }
}
