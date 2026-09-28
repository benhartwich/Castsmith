<?php
declare(strict_types=1);

namespace Castsmith\Settings;

use Castsmith\Db\Schema;
use Castsmith\Health\Registry;
use Castsmith\Jobs\AdminActions;
use Castsmith\Jobs\Scheduler;
use Castsmith\Support\KeyStore;

/**
 * The settings page under Tools.
 *
 * It shows three things: the credentials, the state of the four services and
 * proof that the job chain is running.
 */
final class SettingsPage
{
    public const MENU_SLUG   = 'castsmith';
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
            \Castsmith\Admin\EpisodesPage::MENU_SLUG,
            __('Castsmith — Settings', 'castsmith'),
            __('Settings', 'castsmith'),
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
            \Castsmith\Admin\Assets::version('assets/admin.css')
        );

        wp_enqueue_script(
            'aaspf-admin',
            AASPF_PLUGIN_URL . 'assets/admin.js',
            [],
            \Castsmith\Admin\Assets::version('assets/admin.js'),
            true
        );

        wp_localize_script('aaspf-admin', 'aaspfAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('aaspf_health'),
            'action'  => 'aaspf_health_check',
            'strings' => [
                'running' => __('checking …', 'castsmith'),
                'failed'  => __('Check failed.', 'castsmith'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'castsmith'));
        }

        echo '<div class="wrap aaspf-wrap">';
        echo '<h1>' . esc_html__('Castsmith', 'castsmith') . '</h1>';

        settings_errors(Options::OPTION);
        AdminActions::renderNotice();
        \Castsmith\Admin\EpisodeActions::renderNotice();

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
        echo '<p><strong>' . esc_html__('Credentials could not be stored yet.', 'castsmith') . '</strong></p>';
        echo '<p>' . esc_html(KeyStore::missingKeyMessage()) . '</p>';
        echo '<p>' . esc_html__('Add this line to wp-config.php, above "That\'s all, stop editing!":', 'castsmith') . '</p>';
        echo '<pre class="aaspf-config-line"><code>' . esc_html($suggestion) . '</code></pre>';
        echo '<p class="description">' . esc_html__('A new key is suggested on every page load. Once it is in wp-config.php, do not change it — otherwise the credentials already stored will no longer be readable.', 'castsmith') . '</p>';
        echo '</div>';
    }

    private static function renderForm(): void
    {
        $values = Options::all();

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);

        self::renderSection(
            __('Podcast', 'castsmith'),
            __('Details used in prompts, show notes, notices and file names.', 'castsmith'),
            [
                self::textRow('podcast_name', __('Podcast name', 'castsmith'), $values, sprintf(
                    /* translators: %s: derived name */
                    __('Leave empty to use the title from Podlove or the site name. Currently: %s', 'castsmith'),
                    Options::podcastName()
                )),
                self::selectRow('podcast_language', __('Podcast language', 'castsmith'), $values, [
                    /* translators: %s: name of the site language (Deutsch or English) */
                    ''   => sprintf(__('same as the site (%s)', 'castsmith'), Options::language() === 'de' ? 'Deutsch' : 'English'),
                    'de' => 'Deutsch',
                    'en' => 'English',
                ], __('Determines the bundled prompts and the number matching.', 'castsmith')),
                self::textRow('podcast_host', __('Host', 'castsmith'), $values, __('Whose voice reads as the clone. Appears in the AI notice ({host}) and in the greeting.', 'castsmith')),
                self::textRow('podcast_editor', __('Editor', 'castsmith'), $values, __('Optional. Who writes the drafts; mentioned in the greeting ({editor}).', 'castsmith')),
                self::textRow('podcast_sign_off', __('Sign-off', 'castsmith'), $values, __('Optional. A fixed farewell at the end of every episode ({sign_off}).', 'castsmith')),
                self::textRow('file_prefix', __('File name prefix', 'castsmith'), $values, sprintf(
                    /* translators: %s: example */
                    __('Letters and digits only. Empty: derived from the name. Example: %s', 'castsmith'),
                    Options::filePrefix() . 'Oktober2026.mp3'
                )),
            ]
        );

        self::renderSection(
            __('ElevenLabs', 'castsmith'),
            __('Speech synthesis and Voice Changer.', 'castsmith'),
            [
                self::secretRow('elevenlabs_api_key', __('API key', 'castsmith'), $values),
                self::textRow('elevenlabs_voice_id', __('Voice ID', 'castsmith'), $values, __('ID of the voice clone.', 'castsmith')),
                self::textRow('elevenlabs_model_id', __('Model', 'castsmith'), $values, __('Recommended: eleven_v3 — only this model applies phonetic (IPA) pronunciation rules; eleven_multilingual_v2 ignores them.', 'castsmith')),
                self::textRow('elevenlabs_dictionary_id', __('Dictionary ID', 'castsmith'), $values, __('There is exactly one dictionary, which grows through new versions. If it is created anew instead of extended, all existing bindings point to nothing.', 'castsmith')),
                self::textRow('elevenlabs_dictionary_version_id', __('Dictionary version', 'castsmith'), $values, __('Required. Passed explicitly with every request so that later maintenance does not retroactively change the result of a rerun.', 'castsmith')),
            ]
        );

        self::renderSection(
            __('Anthropic', 'castsmith'),
            __('Editing the fact script into the spoken script.', 'castsmith'),
            [
                self::secretRow('anthropic_api_key', __('API key', 'castsmith'), $values),
                self::textRow('anthropic_model', __('Model', 'castsmith'), $values, __('For extraction, script, editing, fact check, metadata and dictionary. Since 25.09.2026 claude-opus-5-5: better than Opus 5 and 20 % cheaper.', 'castsmith')),
                self::textRow('anthropic_model_research', __('Model for web research', 'castsmith'), $values, __('Research is supplementary; claude-sonnet-5 costs less than half.', 'castsmith')),
                self::checkboxRow('anthropic_batch', __('Batch API', 'castsmith'), $values, __('Model calls at half price via the Batch API. The result usually arrives within an hour instead of immediately; the chain then continues on its own. Web research always runs directly.', 'castsmith')),
                self::textRow('anthropic_workspace_id', __('Workspace ID', 'castsmith'), $values, __('Only needed if the key is identity-bound — then the API requires the anthropic-workspace-id header. Leave empty if the key is already bound to a workspace.', 'castsmith')),
            ]
        );

        self::renderSection(
            __('Auphonic', 'castsmith'),
            __('Production of the final file.', 'castsmith'),
            [
                self::secretRow('auphonic_api_key', __('API token', 'castsmith'), $values),
                self::textRow('auphonic_preset', __('Preset UUID', 'castsmith'), $values, __('Speech recognition must be switched off in the preset — chapters and transcript come from the plugin.', 'castsmith')),
            ]
        );

        self::renderSection(
            __('Disclosure', 'castsmith'),
            __('Appears at the end of every episode\'s show notes. The text is copied when an episode is created — so a later change only affects new episodes and does not rewrite drafts that already exist.', 'castsmith'),
            [
                self::textareaRow(
                    'ai_disclosure_text',
                    __('Notice in the show notes', 'castsmith'),
                    $values,
                    __('Leaving it empty suppresses the notice entirely.', 'castsmith')
                ),
                self::checkboxRow(
                    'ai_disclosure_in_audio',
                    __('Notice in the audio as well', 'castsmith'),
                    $values,
                    __('Applies to episodes created afterwards. The sentence is spoken as a separate segment before the sign-off.', 'castsmith')
                ),
                self::textareaRow(
                    'ai_disclosure_audio_text',
                    __('Spoken notice', 'castsmith'),
                    $values,
                    __('Spoken verbatim. Write out abbreviations such as “AI”.', 'castsmith')
                ),
            ]
        );

        self::renderSection(
            __('Notifications', 'castsmith'),
            '',
            [
                self::textRow('notify_email', __('Notify', 'castsmith'), $values, sprintf(
                    /* translators: %s: address of the site admin */
                    __('Separate multiple addresses with commas. Empty means: the admin address (%s). E-mails are sent when a text approval or an audio approval is pending and on errors — only for episodes that proceed automatically.', 'castsmith'),
                    (string) get_option('admin_email')
                )),
            ]
        );

        /**
         * Action: add-ons insert their own sections here, using
         * SettingsPage::renderSection() and the *Row() helpers.
         */
        do_action('castsmith_settings_sections', $values);

        self::renderSection(
            __('Opener and outro', 'castsmith'),
            __('The assembly places the audio logo before the episode and the outro after it, in stereo, and shifts chapters and transcript accordingly. The files are uploaded below.', 'castsmith'),
            [
                self::checkboxRow('music_enabled', __('Use', 'castsmith'), $values, __('Mix opener and outro into every new assembly.', 'castsmith')),
            ]
        );

        self::renderSection(
            __('Tools', 'castsmith'),
            __('External programs for the assembly.', 'castsmith'),
            [
                self::selectRow('montage_mode', __('Assembly', 'castsmith'), $values, [
                    'auto'   => __('automatic', 'castsmith'),
                    'ffmpeg' => __('ffmpeg on this server', 'castsmith'),
                    'php'    => __('PHP, music via Auphonic', 'castsmith'),
                ], sprintf(
                    /* translators: %s: why the current path is active */
                    __('With ffmpeg, the plugin mixes the music itself (bridges fade under the next chapter). Without it, segments are joined in PHP and Auphonic adds opener, bridges and outro. Currently: %s', 'castsmith'),
                    \Castsmith\Audio\AudioEngine::reason()
                )),
                self::textRow('ffmpeg_path', __('ffmpeg', 'castsmith'), $values, __('Program name or absolute path.', 'castsmith')),
                self::textRow('ffprobe_path', __('ffprobe', 'castsmith'), $values, __('Needed for the measured segment duration.', 'castsmith')),
                self::textRow(
                    'paragraph_pause_ms',
                    __('Pause between paragraphs (ms)', 'castsmith'),
                    $values,
                    __('Without this pause one paragraph runs into the next. The model\'s own sentence pauses are two to four tenths of a second — the paragraph pause should be longer.', 'castsmith')
                ),
                self::textRow(
                    'chapter_pause_ms',
                    __('Pause at chapter boundaries (ms)', 'castsmith'),
                    $values,
                    __('Inserted as silence, not as a break tag — only this keeps the chapter time exact. Both pauses are filled with room tone from the recording itself and faded in and out softly at the edges.', 'castsmith')
                ),
                self::checkboxRow(
                    'delete_data_on_uninstall',
                    __('Delete everything on uninstall', 'castsmith'),
                    $values,
                    __('Removes tables and settings when the plugin is deleted. Off by default.', 'castsmith')
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
        echo '<h2>' . esc_html__('Opener, outro and separators: files', 'castsmith') . '</h2>';
        echo '<p class="description">' . esc_html__('Separators are short musical bridges between chapters (about 8 to 12 seconds). They start shortly after the last word; the next chapter begins 1.5 seconds before their end while the assembly fades them out. Up to five, used in rotation. Trim silence at the end of the file beforehand. Without separators the silent chapter pause is used.', 'castsmith') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(\Castsmith\Admin\EpisodeActions::ACTION_MUSIC);
        echo '<input type="hidden" name="action" value="' . esc_attr(\Castsmith\Admin\EpisodeActions::ACTION_MUSIC) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        $slots = [
            'opener' => __('Opener (audio logo)', 'castsmith'),
            'outro'  => __('Outro', 'castsmith'),
        ];
        foreach (\Castsmith\Audio\MusicBed::SEPARATOR_SLOTS as $n => $slot) {
            /* translators: %d: separator number */
            $slots[$slot] = sprintf(__('Separator %d', 'castsmith'), $n + 1);
        }

        foreach ($slots as $slot => $label) {
            $file = \Castsmith\Audio\MusicBed::file($slot);
            echo '<tr><th scope="row"><label for="aaspf-musik-' . esc_attr($slot) . '">' . esc_html($label) . '</label></th><td>';
            if (is_readable($file)) {
                echo '<audio controls preload="none" src="' . esc_url(\Castsmith\Audio\MusicBed::url($slot)) . '"></audio>';
                $ms = \Castsmith\Audio\AudioEngine::durationMs($file);
                /* translators: 1: duration of the audio file in seconds, 2: upload date */
                echo '<p class="description">' . esc_html(sprintf(__('%1$s seconds, uploaded on %2$s.', 'castsmith'), number_format_i18n(($ms ?? 0) / 1000, 1), wp_date('d.m.Y', (int) filemtime($file)))) . '</p>';
                echo '<p><label><input type="checkbox" name="entfernen_' . esc_attr($slot) . '" value="1"> ' . esc_html__('Remove file', 'castsmith') . '</label></p>';
            } else {
                echo '<p class="description">' . esc_html__('No file yet.', 'castsmith') . '</p>';
            }
            echo '<input type="file" id="aaspf-musik-' . esc_attr($slot) . '" name="' . esc_attr($slot) . '" accept="audio/mpeg,.mp3">';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button(__('Upload files', 'castsmith'), 'secondary');
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
        echo wp_kses(implode('', $rows), \Castsmith\Admin\Html::allowed());
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
            $html .= ' <span class="aaspf-badge aaspf-badge-ok">' . esc_html__('saved', 'castsmith') . '</span>';
            $html .= '<p class="description">' . esc_html__('Leaving it empty means: unchanged. To replace it, simply enter the new value.', 'castsmith') . '</p>';
            $html .= '<label><input type="checkbox" name="' . esc_attr(Options::OPTION . '[clear_' . $field . ']') . '" value="1"> ';
            $html .= esc_html__('delete stored value', 'castsmith') . '</label>';
        } else {
            $html .= ' <span class="aaspf-badge aaspf-badge-skip">' . esc_html__('not set', 'castsmith') . '</span>';
        }

        return $html . '</td></tr>';
    }

    private static function renderHealth(): void
    {
        echo '<h2>' . esc_html__('Service status', 'castsmith') . '</h2>';
        echo '<p class="description">' . esc_html__('Each check runs as a separate request. None of them uses up credit.', 'castsmith') . '</p>';

        echo '<table class="widefat striped aaspf-health"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Service', 'castsmith') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'castsmith') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (Registry::all() as $id => $check) {
            echo '<tr data-aaspf-check="' . esc_attr($id) . '">';
            echo '<td><strong>' . esc_html($check->label()) . '</strong></td>';
            echo '<td class="aaspf-health-cell"><span class="aaspf-status aaspf-status-pending">' . esc_html__('not checked yet', 'castsmith') . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="aaspf-run-health">' . esc_html__('Check now', 'castsmith') . '</button></p>';
    }

    private static function renderJobs(): void
    {
        $version = Scheduler::activeVersion();
        $state   = Scheduler::state();

        echo '<h2>' . esc_html__('Background processing', 'castsmith') . '</h2>';

        if ($version === null) {
            echo '<p class="aaspf-status aaspf-status-fail">' . esc_html__('Action Scheduler is not loaded.', 'castsmith') . '</p>';
        } else {
            echo '<p>' . sprintf(
                /* translators: %s: version number */
                esc_html__('Action Scheduler %s is active.', 'castsmith'),
                '<code>' . esc_html($version) . '</code>'
            ) . '</p>';
        }

        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Test job scheduled', 'castsmith') . '</th><td>';
        if (!empty($state['requested_at'])) {
            echo esc_html(self::formatTime((int) $state['requested_at']));
            if (!empty($state['action_id'])) {
                echo ' <code>#' . esc_html((string) (int) $state['action_id']) . '</code>';
            }
        } else {
            echo esc_html__('never', 'castsmith');
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Test job executed', 'castsmith') . '</th><td>';
        $ranMatches = !empty($state['ran_at'])
            && isset($state['token'], $state['ran_token'])
            && hash_equals((string) $state['token'], (string) $state['ran_token']);

        if ($ranMatches) {
            echo '<span class="aaspf-status aaspf-status-ok">' . esc_html(self::formatTime((int) $state['ran_at'])) . '</span>';
        } elseif (!empty($state['requested_at'])) {
            echo '<span class="aaspf-status aaspf-status-warn">' . esc_html__('still pending — Action Scheduler processes the queue via WP-Cron, which takes up to a minute.', 'castsmith') . '</span>';
        } else {
            echo esc_html__('never', 'castsmith');
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(AdminActions::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(AdminActions::ACTION) . '">';
        echo '<p>';
        submit_button(__('Schedule test job', 'castsmith'), 'secondary', 'submit', false);
        echo '</p>';
        echo '</form>';
    }

    private static function renderStorage(): void
    {
        echo '<h2>' . esc_html__('Database', 'castsmith') . '</h2>';
        echo '<table class="widefat striped"><tbody>';

        foreach (Schema::status() as $table => $exists) {
            echo '<tr><th scope="row"><code>' . esc_html($table) . '</code></th><td>';
            if ($exists) {
                echo '<span class="aaspf-status aaspf-status-ok">' . esc_html__('present', 'castsmith') . '</span>';
            } else {
                echo '<span class="aaspf-status aaspf-status-fail">' . esc_html__('missing', 'castsmith') . '</span>';
            }
            echo '</td></tr>';
        }

        echo '<tr><th scope="row">' . esc_html__('Schema version', 'castsmith') . '</th><td><code>';
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
