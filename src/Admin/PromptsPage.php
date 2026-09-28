<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler verifies its nonce first (guard() / check_ajax_referer()).
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters of admin screens.

use PodcastForge\Ai\Prompts;
use PodcastForge\Settings\Options;
use PodcastForge\Text\Sanitizer;

/**
 * View and edit the prompts.
 *
 * For each prompt, the version currently in effect is shown together with its
 * origin. Saving here creates an edited version that takes precedence over the
 * prompt directory and the bundled file. If you leave the default text
 * unchanged or tick the checkbox, nothing custom is saved — that way,
 * improvements to the bundled prompts arrive with updates.
 */
final class PromptsPage
{
    public const SLUG = 'aas-podcast-forge-prompts';
    public const ACTION = 'aaspf_prompts_save';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu'], 11);
        add_action('admin_post_' . self::ACTION, [self::class, 'handleSave']);
    }

    public static function addMenu(): void
    {
        EpisodesPage::addSubpage(__('Podcast Forge — Prompts', 'podcast-forge'), __('Prompts', 'podcast-forge'), self::SLUG, [self::class, 'render']);
    }

    public static function url(string $language = ''): string
    {
        return add_query_arg(array_filter(['page' => self::SLUG, 'sprache' => $language]), admin_url('admin.php'));
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    private static function descriptions(): array
    {
        return [
            'script'     => [__('Spoken script', 'podcast-forge'), __('Turns the fact script into the text the voice speaks. The pronunciation dictionary list is appended automatically.', 'podcast-forge')],
            'metadata'   => [__('Publication data', 'podcast-forge'), __('Titles, descriptions, keywords, chapters and pronunciation candidates. The output format is fixed.', 'podcast-forge')],
            'factcheck'  => [__('Fact check', 'podcast-forge'), __('Compares the spoken script with the source for changed, missing and invented statements.', 'podcast-forge')],
            'dictionary' => [__('Pronunciation dictionary', 'podcast-forge'), __('Decides which terms get a pronunciation rule and suggests phonetic spellings.', 'podcast-forge')],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can(\PodcastForge\Settings\SettingsPage::CAPABILITY)) {
            return;
        }

        $language = isset($_GET['sprache']) ? sanitize_key(wp_unslash((string) $_GET['sprache'])) : Options::language();
        if (!in_array($language, Prompts::LANGUAGES, true)) {
            $language = Options::language();
        }

        echo '<div class="wrap aaspf-wrap">';
        echo '<h1>' . esc_html__('Prompts', 'podcast-forge') . '</h1>';
        echo '<p class="description">' . esc_html__('Placeholders: {podcast}, {host}, {editor}, {sign_off} — they are filled from the settings. A version saved here takes precedence over a file in the prompt directory and over the bundled one.', 'podcast-forge') . '</p>';

        EpisodeActions::renderNotice();

        echo '<nav class="nav-tab-wrapper">';
        foreach (Prompts::LANGUAGES as $lang) {
            printf(
                '<a class="nav-tab%s" href="%s">%s%s</a>',
                $lang === $language ? ' nav-tab-active' : '',
                esc_url(self::url($lang)),
                esc_html(strtoupper($lang)),
                $lang === Options::language() ? ' ' . esc_html__('(podcast language)', 'podcast-forge') : ''
            );
        }
        echo '</nav>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="sprache" value="' . esc_attr($language) . '">';

        foreach (self::descriptions() as $name => [$title, $description]) {
            $raw = Prompts::raw($name, $language);
            $origin = match ($raw['source']) {
                'override'  => __('edited in the admin', 'podcast-forge'),
                /* translators: %s: path of the prompt file */
                'directory' => sprintf(__('File in the prompt directory: %s', 'podcast-forge'), $raw['path']),
                default     => __('bundled', 'podcast-forge'),
            };

            echo '<section class="aaspf-panel" id="prompt-' . esc_attr($name) . '">';
            echo '<h2>' . esc_html($title) . ' <span class="aaspf-pille aaspf-pille-' . ($raw['source'] === 'builtin' ? 'ok' : 'warn') . '">' . esc_html($origin) . '</span></h2>';
            echo '<p class="description">' . esc_html($description) . '</p>';
            printf(
                '<textarea name="prompt[%1$s]" rows="18" class="large-text code" aria-label="%2$s">%3$s</textarea>',
                esc_attr($name),
                esc_attr($title),
                esc_textarea($raw['text'])
            );
            if ($raw['source'] === 'override') {
                echo '<p><label><input type="checkbox" name="zuruecksetzen[' . esc_attr($name) . ']" value="1"> '
                    . esc_html__('Discard edits and reset to the file or the default', 'podcast-forge') . '</label></p>';
            }
            echo '</section>';
        }

        submit_button(__('Save prompts', 'podcast-forge'));
        echo '</form></div>';
    }

    public static function handleSave(): void
    {
        EpisodeActions::guard(self::ACTION);

        $language = isset($_POST['sprache']) ? sanitize_key(wp_unslash((string) $_POST['sprache'])) : '';
        if (!in_array($language, Prompts::LANGUAGES, true)) {
            wp_die(esc_html__('Unknown language.', 'podcast-forge'), '', ['response' => 400]);
        }

        foreach (Prompts::NAMES as $name) {
            if (!empty($_POST['zuruecksetzen'][$name])) {
                Prompts::saveOverride($name, $language, '');
                continue;
            }
            if (!isset($_POST['prompt'][$name]) || !is_string($_POST['prompt'][$name])) {
                continue;
            }

            // Prompts are instructions for a model, not HTML: the allowlist
            // keeps text and pause tags (see Sanitizer).
            $submitted = Sanitizer::script(wp_unslash($_POST['prompt'][$name])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Sanitizer::script()

            // Unchanged compared to the file or default: do not save a custom
            // version. Compared after sanitising, so that a file with other
            // markup does not turn into an override just by saving the page.
            $current = Prompts::raw($name, $language);
            if ($current['source'] !== 'override' && $submitted === Sanitizer::script($current['text'])) {
                continue;
            }

            Prompts::saveOverride($name, $language, $submitted);
        }

        EpisodeActions::notice('success', __('Prompts saved.', 'podcast-forge'));
        wp_safe_redirect(self::url($language));
        exit;
    }
}
