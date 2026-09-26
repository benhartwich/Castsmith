<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

use PodcastForge\Db\EpisodeRepository;
use PodcastForge\Segments\SegmentRepository;
use PodcastForge\Settings\SettingsPage;
use PodcastForge\Voice\DictionaryWriter;
use PodcastForge\Voice\PronunciationDictionary;

/**
 * Path B: create a dictionary rule directly from the segment row.
 *
 * The two paths are deliberately ordered: the dictionary rule comes first.
 * For a recurring proper name, re-recording is the wrong answer — it fixes
 * the problem in this one episode and in no other.
 */
final class DictionaryController
{
    public const ACTION = 'aaspf_add_rule';
    public const NONCE  = 'aaspf_rule';

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

        $grapheme = isset($_POST['grapheme']) ? sanitize_text_field(wp_unslash((string) $_POST['grapheme'])) : '';
        $alias = isset($_POST['alias']) ? sanitize_text_field(wp_unslash((string) $_POST['alias'])) : '';
        $ipa = isset($_POST['ipa']) ? sanitize_text_field(wp_unslash((string) $_POST['ipa'])) : '';
        $segmentId = isset($_POST['segment']) ? (int) $_POST['segment'] : 0;

        try {
            $result = DictionaryWriter::addRule($grapheme, $ipa, $alias);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }

        // The cache is keyed to the version identifier and therefore expires
        // on its own. Clear it anyway, just to be safe.
        PronunciationDictionary::forget();

        $segment = SegmentRepository::find($segmentId);
        if ($segment !== null) {
            EpisodeRepository::log((int) $segment['episode_id'], 'woerterbuch', sprintf(
                /* translators: 1: created or replaced, 2: rule type (phoneme or alias), 3: term, 4: IPA or alias pronunciation, 5: dictionary version ID, 6: number of rules */
                __('Rule %1$s (%2$s): "%3$s" is spoken as "%4$s". New version %5$s with %6$d rules.', 'podcast-forge'),
                !empty($result['ersetzt']) ? __('replaced', 'podcast-forge') : __('created', 'podcast-forge'),
                (string) ($result['art'] ?? ''),
                $grapheme,
                $result['art'] === 'phoneme' ? $ipa : $alias,
                $result['version_id'],
                $result['rules']
            ));
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: 1: created or replaced, 2: term, 3: number of rules */
                __('Rule for "%2$s" %1$s. The dictionary now has %3$d rules and is bound. Regenerate the affected segments for it to take effect.', 'podcast-forge'),
                !empty($result['ersetzt']) ? __('replaced', 'podcast-forge') : __('created', 'podcast-forge'),
                $grapheme,
                $result['rules']
            ),
            'version' => $result['version_id'],
            'datei'   => $result['datei'],
        ]);
    }
}
