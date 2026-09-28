<?php
declare(strict_types=1);

namespace PodcastForge\Settings;

use PodcastForge\Db\Schema;
use PodcastForge\Health\Registry;
use PodcastForge\Jobs\AdminActions;
use PodcastForge\Jobs\Scheduler;
use PodcastForge\Support\KeyStore;

/**
 * The settings page under Tools.
 *
 * It shows three things: the credentials, the state of the four services and
 * proof that the job chain is running.
 */
final class SettingsPage
{
    public const MENU_SLUG   = 'aas-podcast-forge';
    public const CAPABILITY  = 'manage_options';
    public const OPTION_GROUP = 'aaspf_settings_group';

    private static string $hookSuffix = '';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function addMenu(): void
    {
        $hook = add_submenu_page(
            \PodcastForge\Admin\EpisodesPage::MENU_SLUG,
            __('Podcast Forge — Settings', 'podcast-forge'),
            __('Settings', 'podcast-forge'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render']
        );

        self::$hookSuffix = is_string($hook) ? $hook : '';
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Options::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [SettingsSanitizer::class, 'sanitize'],
            'default'           => Options::defaults(),
        ]);
    }

    public static function enqueue(string $hookSuffix): void
    {
        if ($hookSuffix === '' || $hookSuffix !== self::$hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'aaspf-admin',
            AASPF_PLUGIN_URL . 'assets/admin.css',
            [],
            \PodcastForge\Admin\Assets::version('assets/admin.css')
        );

        wp_enqueue_script(
            'aaspf-admin',
            AASPF_PLUGIN_URL . 'assets/admin.js',
            [],
            \PodcastForge\Admin\Assets::version('assets/admin.js'),
            true
        );

        wp_localize_script('aaspf-admin', 'aaspfAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('aaspf_health'),
            'action'  => 'aaspf_health_check',
            'strings' => [
                'running' => __('checking …', 'podcast-forge'),
                'failed'  => __('Check failed.', 'podcast-forge'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'podcast-forge'));
        }

        echo '<div class="wrap aaspf-wrap">';
        echo '<h1>' . esc_html__('Podcast Forge', 'podcast-forge') . '</h1>';

        settings_errors(Options::OPTION);
        AdminActions::renderNotice();
        \PodcastForge\Admin\EpisodeActions::renderNotice();

        self::renderKeyStatus();
        self::renderForm();
        self::renderHealth();
        self::renderJobs();
        self::renderStorage();

        echo '</div>';
    }

    private static function renderKeyStatus(): void
    {
        if (KeyStore::isConfigured()) {
            return;
        }

        // One suggestion per page load, otherwise every reload shows a different one.
        static $suggestion = null;
        if ($suggestion === null) {
            $suggestion = KeyStore::suggestedConfigLine();
        }

        echo '<div class="notice notice-error aaspf-keynotice">';
        echo '<p><strong>' . esc_html__('Credentials could not be stored yet.', 'podcast-forge') . '</strong></p>';
        echo '<p>' . esc_html(KeyStore::missingKeyMessage()) . '</p>';
        echo '<p>' . esc_html__('Add this line to wp-config.php, above "That\'s all, stop editing!":', 'podcast-forge') . '</p>';
        echo '<pre class="aaspf-config-line"><code>' . esc_html($suggestion) . '</code></pre>';
        echo '<p class="description">' . esc_html__('A new key is suggested on every page load. Once it is in wp-config.php, do not change it — otherwise the credentials already stored will no longer be readable.', 'podcast-forge') . '</p>';
        echo '</div>';
    }

    private static function renderForm(): void
    {
        $values = Options::all();

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);

        self::renderSection(
            __('Podcast', 'podcast-forge'),
            __('Details used in prompts, show notes, notices and file names.', 'podcast-forge'),
            [
                self::textRow('podcast_name', __('Podcast name', 'podcast-forge'), $values, sprintf(
                    /* translators: %s: derived name */
                    __('Leave empty to use the title from Podlove or the site name. Currently: %s', 'podcast-forge'),
                    Options::podcastName()
                )),
                self::selectRow('podcast_language', __('Podcast language', 'podcast-forge'), $values, [
                    /* translators: %s: name of the site language (Deutsch or English) */
                    ''   => sprintf(__('same as the site (%s)', 'podcast-forge'), Options::language() === 'de' ? 'Deutsch' : 'English'),
                    'de' => 'Deutsch',
                    'en' => 'English',
                ], __('Determines the bundled prompts and the number matching.', 'podcast-forge')),
                self::textRow('podcast_host', __('Host', 'podcast-forge'), $values, __('Whose voice reads as the clone. Appears in the AI notice ({host}) and in the greeting.', 'podcast-forge')),
                self::textRow('podcast_editor', __('Editor', 'podcast-forge'), $values, __('Optional. Who writes the drafts; mentioned in the greeting ({editor}).', 'podcast-forge')),
                self::textRow('podcast_sign_off', __('Sign-off', 'podcast-forge'), $values, __('Optional. A fixed farewell at the end of every episode ({sign_off}).', 'podcast-forge')),
                self::textRow('file_prefix', __('File name prefix', 'podcast-forge'), $values, sprintf(
                    /* translators: %s: example */
                    __('Letters and digits only. Empty: derived from the name. Example: %s', 'podcast-forge'),
                    Options::filePrefix() . 'Oktober2026.mp3'
                )),
            ]
        );

        self::renderSection(
            __('ElevenLabs', 'podcast-forge'),
            __('Speech synthesis and Voice Changer.', 'podcast-forge'),
            [
                self::secretRow('elevenlabs_api_key', __('API key', 'podcast-forge'), $values),
                self::textRow('elevenlabs_voice_id', __('Voice ID', 'podcast-forge'), $values, __('ID of the voice clone.', 'podcast-forge')),
                self::textRow('elevenlabs_model_id', __('Model', 'podcast-forge'), $values, __('Recommended: eleven_v3 — only this model applies phonetic (IPA) pronunciation rules; eleven_multilingual_v2 ignores them.', 'podcast-forge')),
                self::textRow('elevenlabs_dictionary_id', __('Dictionary ID', 'podcast-forge'), $values, __('There is exactly one dictionary, which grows through new versions. If it is created anew instead of extended, all existing bindings point to nothing.', 'podcast-forge')),
                self::textRow('elevenlabs_dictionary_version_id', __('Dictionary version', 'podcast-forge'), $values, __('Required. Passed explicitly with every request so that later maintenance does not retroactively change the result of a rerun.', 'podcast-forge')),
            ]
        );

        self::renderSection(
            __('Anthropic', 'podcast-forge'),
            __('Editing the fact script into the spoken script.', 'podcast-forge'),
            [
                self::secretRow('anthropic_api_key', __('API key', 'podcast-forge'), $values),
                self::textRow('anthropic_model', __('Model', 'podcast-forge'), $values, __('For extraction, script, editing, fact check, metadata and dictionary. Since 25.09.2026 claude-opus-5-5: better than Opus 5 and 20 % cheaper.', 'podcast-forge')),
                self::textRow('anthropic_model_research', __('Model for web research', 'podcast-forge'), $values, __('Research is supplementary; claude-sonnet-5 costs less than half.', 'podcast-forge')),
                self::checkboxRow('anthropic_batch', __('Batch API', 'podcast-forge'), $values, __('Model calls at half price via the Batch API. The result usually arrives within an hour instead of immediately; the chain then continues on its own. Web research always runs directly.', 'podcast-forge')),
                self::textRow('anthropic_workspace_id', __('Workspace ID', 'podcast-forge'), $values, __('Only needed if the key is identity-bound — then the API requires the anthropic-workspace-id header. Leave empty if the key is already bound to a workspace.', 'podcast-forge')),
            ]
        );

        self::renderSection(
            __('Auphonic', 'podcast-forge'),
            __('Production of the final file.', 'podcast-forge'),
            [
                self::secretRow('auphonic_api_key', __('API token', 'podcast-forge'), $values),
                self::textRow('auphonic_preset', __('Preset UUID', 'podcast-forge'), $values, __('Speech recognition must be switched off in the preset — chapters and transcript come from the plugin.', 'podcast-forge')),
            ]
        );

        self::renderSection(
            __('Disclosure', 'podcast-forge'),
            __('Appears at the end of every episode\'s show notes. The text is copied when an episode is created — so a later change only affects new episodes and does not rewrite drafts that already exist.', 'podcast-forge'),
            [
                self::textareaRow(
                    'ai_disclosure_text',
                    __('Notice in the show notes', 'podcast-forge'),
                    $values,
                    __('Leaving it empty suppresses the notice entirely.', 'podcast-forge')
                ),
                self::checkboxRow(
                    'ai_disclosure_in_audio',
                    __('Notice in the audio as well', 'podcast-forge'),
                    $values,
                    __('Applies to episodes created afterwards. The sentence is spoken as a separate segment before the sign-off.', 'podcast-forge')
                ),
                self::textareaRow(
                    'ai_disclosure_audio_text',
                    __('Spoken notice', 'podcast-forge'),
                    $values,
                    __('Spoken verbatim. Write out abbreviations such as “AI”.', 'podcast-forge')
                ),
            ]
        );

        self::renderSection(
            __('Notifications', 'podcast-forge'),
            '',
            [
                self::textRow('notify_email', __('Notify', 'podcast-forge'), $values, sprintf(
                    /* translators: %s: address of the site admin */
                    __('Separate multiple addresses with commas. Empty means: the admin address (%s). E-mails are sent when a text approval or an audio approval is pending and on errors — only for episodes that proceed automatically.', 'podcast-forge'),
                    (string) get_option('admin_email')
                )),
            ]
        );

        /**
         * Action: add-ons insert their own sections here, using
         * SettingsPage::renderSection() and the *Row() helpers.
         */
        do_action('podcast_forge_settings_sections', $values);

        self::renderSection(
            __('Opener and outro', 'podcast-forge'),
            __('The assembly places the audio logo before the episode and the outro after it, in stereo, and shifts chapters and transcript accordingly. The files are uploaded below.', 'podcast-forge'),
            [
                self::checkboxRow('music_enabled', __('Use', 'podcast-forge'), $values, __('Mix opener and outro into every new assembly.', 'podcast-forge')),
            ]
        );

        self::renderSection(
            __('Tools', 'podcast-forge'),
            __('External programs for the assembly.', 'podcast-forge'),
            [
                self::selectRow('montage_mode', __('Assembly', 'podcast-forge'), $values, [
                    'auto'   => __('automatic', 'podcast-forge'),
                    'ffmpeg' => __('ffmpeg on this server', 'podcast-forge'),
                    'php'    => __('PHP, music via Auphonic', 'podcast-forge'),
                ], sprintf(
                    /* translators: %s: why the current path is active */
                    __('With ffmpeg, the plugin mixes the music itself (bridges fade under the next chapter). Without it, segments are joined in PHP and Auphonic adds opener, bridges and outro. Currently: %s', 'podcast-forge'),
                    \PodcastForge\Audio\AudioEngine::reason()
                )),
                self::textRow('ffmpeg_path', __('ffmpeg', 'podcast-forge'), $values, __('Program name or absolute path.', 'podcast-forge')),
                self::textRow('ffprobe_path', __('ffprobe', 'podcast-forge'), $values, __('Needed for the measured segment duration.', 'podcast-forge')),
                self::textRow(
                    'paragraph_pause_ms',
                    __('Pause between paragraphs (ms)', 'podcast-forge'),
                    $values,
                    __('Without this pause one paragraph runs into the next. The model\'s own sentence pauses are two to four tenths of a second — the paragraph pause should be longer.', 'podcast-forge')
                ),
                self::textRow(
                    'chapter_pause_ms',
                    __('Pause at chapter boundaries (ms)', 'podcast-forge'),
                    $values,
                    __('Inserted as silence, not as a break tag — only this keeps the chapter time exact. Both pauses are filled with room tone from the recording itself and faded in and out softly at the edges.', 'podcast-forge')
                ),
                self::checkboxRow(
                    'delete_data_on_uninstall',
                    __('Delete everything on uninstall', 'podcast-forge'),
                    $values,
                    __('Removes tables and settings when the plugin is deleted. Off by default.', 'podcast-forge')
                ),
            ]
        );

        submit_button();
        echo '</form>';

        self::renderMusicUpload();
    }

    /**
     * Upload and preview opener and outro. A separate form, because
     * options.php does not accept files.
     */
    private static function renderMusicUpload(): void
    {
        echo '<h2>' . esc_html__('Opener, outro and separators: files', 'podcast-forge') . '</h2>';
        echo '<p class="description">' . esc_html__('Separators are short musical bridges between chapters (about 8 to 12 seconds). They start shortly after the last word; the next chapter begins 1.5 seconds before their end while the assembly fades them out. Up to five, used in rotation. Trim silence at the end of the file beforehand. Without separators the silent chapter pause is used.', 'podcast-forge') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(\PodcastForge\Admin\EpisodeActions::ACTION_MUSIC);
        echo '<input type="hidden" name="action" value="' . esc_attr(\PodcastForge\Admin\EpisodeActions::ACTION_MUSIC) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        $slots = [
            'opener' => __('Opener (audio logo)', 'podcast-forge'),
            'outro'  => __('Outro', 'podcast-forge'),
        ];
        foreach (\PodcastForge\Audio\MusicBed::SEPARATOR_SLOTS as $n => $slot) {
            /* translators: %d: separator number */
            $slots[$slot] = sprintf(__('Separator %d', 'podcast-forge'), $n + 1);
        }

        foreach ($slots as $slot => $label) {
            $file = \PodcastForge\Audio\MusicBed::file($slot);
            echo '<tr><th scope="row"><label for="aaspf-musik-' . esc_attr($slot) . '">' . esc_html($label) . '</label></th><td>';
            if (is_readable($file)) {
                echo '<audio controls preload="none" src="' . esc_url(\PodcastForge\Audio\MusicBed::url($slot)) . '"></audio>';
                $ms = \PodcastForge\Audio\AudioEngine::durationMs($file);
                /* translators: 1: duration of the audio file in seconds, 2: upload date */
                echo '<p class="description">' . esc_html(sprintf(__('%1$s seconds, uploaded on %2$s.', 'podcast-forge'), number_format_i18n(($ms ?? 0) / 1000, 1), wp_date('d.m.Y', (int) filemtime($file)))) . '</p>';
                echo '<p><label><input type="checkbox" name="entfernen_' . esc_attr($slot) . '" value="1"> ' . esc_html__('Remove file', 'podcast-forge') . '</label></p>';
            } else {
                echo '<p class="description">' . esc_html__('No file yet.', 'podcast-forge') . '</p>';
            }
            echo '<input type="file" id="aaspf-musik-' . esc_attr($slot) . '" name="' . esc_attr($slot) . '" accept="audio/mpeg,.mp3">';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button(__('Upload files', 'podcast-forge'), 'secondary');
        echo '</form>';
    }

    /**
     * @param string[] $rows
     */
    public static function renderSection(string $title, string $description, array $rows): void
    {
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '<table class="form-table" role="presentation"><tbody>';
        echo wp_kses(implode('', $rows), \PodcastForge\Admin\Html::allowed());
        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function textRow(string $field, string $label, array $values, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html  = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<input type="text" class="regular-text" id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '"';
        $html .= ' value="' . esc_attr((string) ($values[$field] ?? '')) . '">';
        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        return $html . '</td></tr>';
    }

    /**
     * @param array<string,mixed> $values
     */
    /**
     * @param array<string,mixed>  $values
     * @param array<string,string> $choices value => display text
     */
    public static function selectRow(string $field, string $label, array $values, array $choices, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '">';
        foreach ($choices as $value => $text) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) ($values[$field] ?? ''), (string) $value, false) . '>' . esc_html($text) . '</option>';
        }
        $html .= '</select>';
        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        return $html . '</td></tr>';
    }

    public static function checkboxRow(string $field, string $label, array $values, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html  = '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        $html .= '<label for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '" value="1"';
        $html .= checked(!empty($values[$field]), true, false) . '> ';
        $html .= esc_html($description);

        return $html . '</label></td></tr>';
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function textareaRow(string $field, string $label, array $values, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html  = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<textarea id="' . esc_attr($id) . '" rows="5" class="large-text"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '">';
        $html .= esc_textarea((string) ($values[$field] ?? ''));
        $html .= '</textarea>';
        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        return $html . '</td></tr>';
    }

    /**
     * Secret field: always empty, with an indication of the stored state.
     *
     * @param array<string,mixed> $values
     */
    private static function secretRow(string $field, string $label, array $values): string
    {
        $id = 'aaspf-' . $field;
        $stored = isset($values[$field]) && $values[$field] !== '';

        $html  = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<input type="password" class="regular-text" autocomplete="new-password" id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '" value="">';

        if ($stored) {
            $html .= ' <span class="aaspf-badge aaspf-badge-ok">' . esc_html__('saved', 'podcast-forge') . '</span>';
            $html .= '<p class="description">' . esc_html__('Leaving it empty means: unchanged. To replace it, simply enter the new value.', 'podcast-forge') . '</p>';
            $html .= '<label><input type="checkbox" name="' . esc_attr(Options::OPTION . '[clear_' . $field . ']') . '" value="1"> ';
            $html .= esc_html__('delete stored value', 'podcast-forge') . '</label>';
        } else {
            $html .= ' <span class="aaspf-badge aaspf-badge-skip">' . esc_html__('not set', 'podcast-forge') . '</span>';
        }

        return $html . '</td></tr>';
    }

    private static function renderHealth(): void
    {
        echo '<h2>' . esc_html__('Service status', 'podcast-forge') . '</h2>';
        echo '<p class="description">' . esc_html__('Each check runs as a separate request. None of them uses up credit.', 'podcast-forge') . '</p>';

        echo '<table class="widefat striped aaspf-health"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Service', 'podcast-forge') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'podcast-forge') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (Registry::all() as $id => $check) {
            echo '<tr data-aaspf-check="' . esc_attr($id) . '">';
            echo '<td><strong>' . esc_html($check->label()) . '</strong></td>';
            echo '<td class="aaspf-health-cell"><span class="aaspf-status aaspf-status-pending">' . esc_html__('not checked yet', 'podcast-forge') . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="aaspf-run-health">' . esc_html__('Check now', 'podcast-forge') . '</button></p>';
    }

    private static function renderJobs(): void
    {
        $version = Scheduler::activeVersion();
        $state   = Scheduler::state();

        echo '<h2>' . esc_html__('Background processing', 'podcast-forge') . '</h2>';

        if ($version === null) {
            echo '<p class="aaspf-status aaspf-status-fail">' . esc_html__('Action Scheduler is not loaded.', 'podcast-forge') . '</p>';
        } else {
            echo '<p>' . sprintf(
                /* translators: %s: version number */
                esc_html__('Action Scheduler %s is active.', 'podcast-forge'),
                '<code>' . esc_html($version) . '</code>'
            ) . '</p>';
        }

        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Test job scheduled', 'podcast-forge') . '</th><td>';
        if (!empty($state['requested_at'])) {
            echo esc_html(self::formatTime((int) $state['requested_at']));
            if (!empty($state['action_id'])) {
                echo ' <code>#' . esc_html((string) (int) $state['action_id']) . '</code>';
            }
        } else {
            echo esc_html__('never', 'podcast-forge');
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Test job executed', 'podcast-forge') . '</th><td>';
        $ranMatches = !empty($state['ran_at'])
            && isset($state['token'], $state['ran_token'])
            && hash_equals((string) $state['token'], (string) $state['ran_token']);

        if ($ranMatches) {
            echo '<span class="aaspf-status aaspf-status-ok">' . esc_html(self::formatTime((int) $state['ran_at'])) . '</span>';
        } elseif (!empty($state['requested_at'])) {
            echo '<span class="aaspf-status aaspf-status-warn">' . esc_html__('still pending — Action Scheduler processes the queue via WP-Cron, which takes up to a minute.', 'podcast-forge') . '</span>';
        } else {
            echo esc_html__('never', 'podcast-forge');
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(AdminActions::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(AdminActions::ACTION) . '">';
        echo '<p>';
        submit_button(__('Schedule test job', 'podcast-forge'), 'secondary', 'submit', false);
        echo '</p>';
        echo '</form>';
    }

    private static function renderStorage(): void
    {
        echo '<h2>' . esc_html__('Database', 'podcast-forge') . '</h2>';
        echo '<table class="widefat striped"><tbody>';

        foreach (Schema::status() as $table => $exists) {
            echo '<tr><th scope="row"><code>' . esc_html($table) . '</code></th><td>';
            if ($exists) {
                echo '<span class="aaspf-status aaspf-status-ok">' . esc_html__('present', 'podcast-forge') . '</span>';
            } else {
                echo '<span class="aaspf-status aaspf-status-fail">' . esc_html__('missing', 'podcast-forge') . '</span>';
            }
            echo '</td></tr>';
        }

        echo '<tr><th scope="row">' . esc_html__('Schema version', 'podcast-forge') . '</th><td><code>';
        echo esc_html((string) get_option('aaspf_db_version', '—'));
        echo '</code></td></tr>';
        echo '</tbody></table>';
    }

    private static function formatTime(int $timestamp): string
    {
        return wp_date(
            (string) get_option('date_format') . ' ' . (string) get_option('time_format') . ':s',
            $timestamp
        ) ?: (string) $timestamp;
    }
}
