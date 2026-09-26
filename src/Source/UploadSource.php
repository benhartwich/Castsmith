<?php
declare(strict_types=1);

namespace PodcastForge\Source;

use PodcastForge\Admin\EpisodeActions;

/**
 * A finished fact script: uploaded as DOCX or pasted in as text.
 */
final class UploadSource extends AbstractSource
{
    public const ID = 'upload';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('Fact script', 'podcast-forge');
    }

    public function description(): string
    {
        return __('A finished fact script as a DOCX file or pasted text.', 'podcast-forge');
    }

    public function renderStartForm(): void
    {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_CREATE);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_CREATE) . '">';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="aaspf-docx">' . esc_html__('Fact script as DOCX', 'podcast-forge') . '</label></th><td>';
        echo '<input type="file" id="aaspf-docx" name="docx" accept=".docx">';
        echo '<p class="description">' . esc_html__('Headings are carried along as chapter hints and never end up in the spoken text. The file is only read and not stored.', 'podcast-forge') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="aaspf-source-text">' . esc_html__('or pasted text', 'podcast-forge') . '</label></th><td>';
        echo '<textarea id="aaspf-source-text" name="source_text" rows="10" class="large-text code" placeholder="' . esc_attr__('Separate paragraphs with blank lines. Lines starting with # are treated as headings.', 'podcast-forge') . '"></textarea>';
        echo '</td></tr>';

        self::autoChainRow();
        echo '</tbody></table>';

        submit_button(__('Import source', 'podcast-forge'));
        echo '</form>';
    }

    /**
     * The "continue automatically after text approval" row, shared by all start forms.
     */
    public static function autoChainRow(): void
    {
        echo '<tr><th scope="row">' . esc_html__('After text approval', 'podcast-forge') . '</th><td>';
        echo '<label><input type="checkbox" name="auto_chain" value="1" checked> '
            . esc_html__('continue automatically up to the Podlove draft and send an e-mail notification', 'podcast-forge') . '</label>';
        echo '</td></tr>';
    }
}
