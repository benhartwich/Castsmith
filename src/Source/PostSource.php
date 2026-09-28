<?php
declare(strict_types=1);

namespace Castsmith\Source;

use Castsmith\Admin\EpisodeActions;
use Castsmith\Text\HtmlParser;
use Castsmith\Text\SourceDocument;

/**
 * A post or page of this WordPress installation as a fact script.
 *
 * The content is read the way WordPress outputs it: blocks are rendered,
 * shortcodes are removed (they often produce galleries, forms or third-party
 * content that should not be read aloud). The structure given by headings is
 * kept as chapter hints, just like with a DOCX file. The post itself is not
 * modified.
 */
final class PostSource extends AbstractSource
{
    public const ID = 'post';

    /** Number of posts shown in the selection list. */
    private const LIST_LIMIT = 100;

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('WordPress post', 'castsmith');
    }

    public function description(): string
    {
        return __('A post or page of this website. Headings become chapters; images and embedded content are dropped.', 'castsmith');
    }

    public function renderStartForm(): void
    {
        $posts = get_posts([
            'post_type'      => self::postTypes(),
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'numberposts'    => self::LIST_LIMIT,
            'suppress_filters' => false,
        ]);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_FROM_POST);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_FROM_POST) . '">';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="aaspf-post">' . esc_html__('Post or page', 'castsmith') . '</label></th><td>';
        echo '<select id="aaspf-post" name="post_id">';
        echo '<option value="">' . esc_html__('— select —', 'castsmith') . '</option>';
        foreach ($posts as $post) {
            $type = get_post_type_object($post->post_type);
            printf(
                '<option value="%d">%s</option>',
                (int) $post->ID,
                esc_html(sprintf(
                    '%s (%s, %s, %s)',
                    wp_trim_words(get_the_title($post) !== '' ? get_the_title($post) : __('untitled', 'castsmith'), 12),
                    $type !== null ? $type->labels->singular_name : $post->post_type,
                    get_post_status_object((string) get_post_status($post))->label ?? $post->post_status,
                    mysql2date('d.m.Y', $post->post_modified)
                ))
            );
        }
        echo '</select>';
        /* translators: %d: number of posts shown in the selection list */
        echo '<p class="description">' . esc_html(sprintf(__('The %d most recently edited. An older post can be selected by its ID:', 'castsmith'), self::LIST_LIMIT)) . ' ';
        echo '<label class="screen-reader-text" for="aaspf-post-id">' . esc_html__('Post ID', 'castsmith') . '</label>';
        echo '<input type="number" min="1" id="aaspf-post-id" name="post_id_manual" class="small-text"></p>';
        echo '</td></tr>';

        UploadSource::autoChainRow();
        echo '</tbody></table>';

        submit_button(__('Import post', 'castsmith'));
        echo '</form>';
    }

    /**
     * The fact script from a post.
     */
    public static function document(\WP_Post $post): SourceDocument
    {
        $html = function_exists('do_blocks') ? do_blocks($post->post_content) : $post->post_content;
        $html = strip_shortcodes($html);

        return HtmlParser::parse($html);
    }

    /**
     * Post types an episode may be created from: public types, excluding
     * attachments and the podcast episodes themselves.
     *
     * @return list<string>
     */
    public static function postTypes(): array
    {
        $types = array_values(array_diff(
            get_post_types(['public' => true]),
            ['attachment', \Castsmith\Podlove\EpisodeDraft::POST_TYPE]
        ));

        /** Filter: post types available for selection as a source. */
        return (array) apply_filters('castsmith_post_source_types', $types);
    }
}
