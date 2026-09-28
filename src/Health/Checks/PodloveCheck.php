<?php
declare(strict_types=1);

namespace Castsmith\Health\Checks;

use Castsmith\Health\CheckInterface;
use Castsmith\Health\Result;

/**
 * Checks whether Podlove is active and reachable.
 *
 * This checks not only the activation state but the PHP class itself —
 * because that is exactly what the publishing step uses to create the draft.
 * Direct writes to the Podlove tables are ruled out,
 * which is why the class, not the table, is the criterion.
 */
final class PodloveCheck implements CheckInterface
{
    private const PLUGIN_FILE = 'podlove-podcasting-plugin-for-wordpress/podlove.php';
    private const EPISODE_CLASS = '\\Podlove\\Model\\Episode';
    private const PODCAST_CLASS = '\\Podlove\\Model\\Podcast';

    public function id(): string
    {
        return 'podlove';
    }

    public function label(): string
    {
        return 'Podlove';
    }

    public function run(): Result
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active(self::PLUGIN_FILE)) {
            return Result::fail(__('Podlove Podcast Publisher is not active.', 'castsmith'));
        }

        if (!class_exists(self::EPISODE_CLASS) || !class_exists(self::PODCAST_CLASS)) {
            return Result::fail(
                __('Podlove is active, but the PHP API is missing.', 'castsmith'),
                /* translators: 1: Podlove episode class name, 2: Podlove podcast class name */
                sprintf(__('Expected the classes %1$s and %2$s.', 'castsmith'), self::EPISODE_CLASS, self::PODCAST_CLASS)
            );
        }

        $version = '';
        $file = WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE;
        if (is_readable($file)) {
            $data = get_file_data($file, ['Version' => 'Version']);
            $version = (string) ($data['Version'] ?? '');
        }

        // Can Podlove find the files the plugin writes?
        $location = \Castsmith\Podlove\MediaStore::location();
        if (!$location['matches_podlove']) {
            return Result::warn(
                __('Podlove\'s media file base URL does not point to this site\'s uploads.', 'castsmith'),
                /* translators: %s: URL to enter in Podlove */
                sprintf(__('Finished episodes are stored in uploads/podcasts. Set the media file base URL in Podlove to %s.', 'castsmith'), trailingslashit($location['url']))
            );
        }
        if (\Castsmith\Podlove\MediaStore::asset('mp3') === null) {
            return Result::warn(__('Podlove has no episode asset for MP3.', 'castsmith'), __('Create an asset with the file type MP3 in Podlove, otherwise the audio file cannot be linked.', 'castsmith'));
        }

        $details = $this->episodeSummary();
        if (\Castsmith\Podlove\MediaStore::asset('vtt') === null) {
            $details .= ' ' . __('No asset for WebVTT transcripts — transcripts are not linked.', 'castsmith');
        }

        return Result::ok(
            /* translators: %s: Podlove plugin version number */
            $version !== '' ? sprintf(__('Active (version %s).', 'castsmith'), $version) : __('Active.', 'castsmith'),
            trim($details)
        );
    }

    /**
     * Counts via Podlove's own API, not via the table.
     *
     * The `podlove_episode` table contains rows for many post types —
     * more than a thousand on this installation, including astrophotos,
     * events and drafts. Only a fraction of them are published episodes.
     * `count_published()` correctly filters on post_type "podcast" and
     * post_status "publish".
     */
    private function episodeSummary(): string
    {
        if (!method_exists(self::EPISODE_CLASS, 'count_published')) {
            return '';
        }

        $published = (int) \Podlove\Model\Episode::count_published();
        /* translators: %s: formatted number of published episodes */
        $summary = sprintf(__('%s published episodes.', 'castsmith'), number_format_i18n($published));

        if (method_exists(self::EPISODE_CLASS, 'get_next_episode_number')) {
            $next = \Podlove\Model\Episode::get_next_episode_number();
            if (is_numeric($next)) {
                /* translators: %d: next episode number */
                $summary .= sprintf(__(' Next episode number: %d.', 'castsmith'), (int) $next);
            }
        }

        return $summary;
    }
}
