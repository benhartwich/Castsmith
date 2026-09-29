<?php
declare(strict_types=1);

namespace Sonoquill\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters of admin screens.

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Admin\DictionaryController;
use Sonoquill\Admin\PatchController;
use Sonoquill\Audio\Transcript;
use Sonoquill\Pipeline\Gate;
use Sonoquill\Podlove\MediaStore;
use Sonoquill\Podlove\SlugBuilder;
use Sonoquill\Rest\AuphonicWebhook;
use Sonoquill\Pipeline\Synthesis;
use Sonoquill\Segments\SegmentRepository;
use Sonoquill\Segments\SegmentStatus;
use Sonoquill\Storage\EpisodeStorage;
use Sonoquill\Settings\SettingsPage;

/**
 * Episode overview and text approval — the first of the two human gates.
 */
final class EpisodesPage
{
    public const MENU_SLUG = 'sonoquill-episodes';

    /** @var list<string> */
    private static array $hookSuffixes = [];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu'], 9);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function addMenu(): void
    {
        // A dedicated top-level menu item, like the gallery plugin, instead of
        // two entries under "Tools".
        add_menu_page(
            __('Sonoquill', 'sonoquill'),
            __('Sonoquill', 'sonoquill'),
            SettingsPage::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render'],
            'dashicons-microphone',
            27
        );

        $hooks = [];
        $hooks[] = add_submenu_page(
            self::MENU_SLUG,
            __('Sonoquill — Overview', 'sonoquill'),
            __('Overview', 'sonoquill'),
            SettingsPage::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render']
        );
        self::$hookSuffixes = array_values(array_filter($hooks, 'is_string'));
    }

    /**
     * A dedicated add-on page inside the plugin's menu, using the plugin's styles.
     * Call it from the `admin_menu` hook with a priority above 9.
     */
    public static function addSubpage(string $pageTitle, string $menuTitle, string $slug, callable $render): void
    {
        $hook = add_submenu_page(self::MENU_SLUG, $pageTitle, $menuTitle, SettingsPage::CAPABILITY, $slug, $render);
        if (is_string($hook)) {
            self::$hookSuffixes[] = $hook;
        }
    }

    public static function enqueue(string $hookSuffix): void
    {
        if (!in_array($hookSuffix, self::$hookSuffixes, true)) {
            return;
        }

        wp_enqueue_style('aaspf-admin', AASPF_PLUGIN_URL . 'assets/admin.css', [], Assets::version('assets/admin.css'));
        wp_enqueue_script('aaspf-folge', AASPF_PLUGIN_URL . 'assets/folge.js', [], Assets::version('assets/folge.js'), true);
        wp_localize_script('aaspf-folge', 'aaspfFolge', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => ProgressController::ACTION,
            'nonce'   => wp_create_nonce(ProgressController::NONCE),
            /* translators: between two numbers: "19 of 43" */
            'of'      => __('of', 'sonoquill'),
        ]);

        wp_enqueue_script('aaspf-segmente', AASPF_PLUGIN_URL . 'assets/segments.js', [], Assets::version('assets/segments.js'), true);
        wp_localize_script('aaspf-segmente', 'aaspfSegmente', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'patchAction'  => PatchController::ACTION,
            'patchNonce'   => wp_create_nonce(PatchController::NONCE),
            'regelAction'  => DictionaryController::ACTION,
            'regelNonce'   => wp_create_nonce(DictionaryController::NONCE),
            'strings'      => [
                'aufnahmeStarten'     => __('Start recording', 'sonoquill'),
                'aufnahmeStoppen'     => __('Stop recording', 'sonoquill'),
                'aufnahmeLaeuft'      => __('Recording — read the whole sentence aloud.', 'sonoquill'),
                'aufnahmeFertig'      => __('Recording done. Listen to it first, then apply it.', 'sonoquill'),
                'wirdUmgewandelt'     => __('Converting into your voice …', 'sonoquill'),
                'keinRecorder'        => __('This browser cannot record audio.', 'sonoquill'),
                'keinMikrofon'        => __('No access to the microphone:', 'sonoquill'),
                'regelLaeuft'         => __('Creating rule …', 'sonoquill'),
                'regelUnvollstaendig' => __('The term or both pronunciation forms are missing. One of the two is enough.', 'sonoquill'),
                'fehler'              => __('The request failed.', 'sonoquill'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(SettingsPage::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'sonoquill'));
        }

        $id = isset($_GET['episode']) ? (int) $_GET['episode'] : 0;
        $episode = $id > 0 ? EpisodeRepository::find($id) : null;

        echo '<div class="wrap aaspf-wrap">';
        EpisodeActions::renderNotice();

        if ($episode !== null) {
            self::renderDetail($episode);
        } elseif (isset($_GET['neu'])) {
            self::renderNewPage();
        } else {
            Overview::render();
        }

        echo '</div>';
    }

    /**
     * Stuck runs, at the very top.
     */
    public static function renderStuckNotice(): void
    {
        $stuck = \Sonoquill\Pipeline\Recovery::stuckEpisodes();
        if ($stuck === []) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>'
            . esc_html__('Something is stuck here.', 'sonoquill') . '</strong></p>';

        foreach ($stuck as $item) {
            printf(
                '<p><a href="%s"><strong>%s</strong></a> — %s</p>',
                esc_url(Urls::episode($item['id'])),
                esc_html($item['titel']),
                esc_html($item['grund'])
            );
        }

        echo '</div>';
    }

    private static function renderNewPage(): void
    {
        echo '<p class="aaspf-zurueck"><a href="' . esc_url(Urls::episodes()) . '">&larr; '
            . esc_html__('Overview', 'sonoquill') . '</a></p>';
        echo '<h1>' . esc_html__('New episode', 'sonoquill') . '</h1>';
        echo '<p class="description">' . esc_html__('Where does the text come from? Once the fact script has been imported, the path is the same for every source: speech script, checks, your text approval, audio.', 'sonoquill') . '</p>';

        foreach (\Sonoquill\Source\Sources::all() as $source) {
            echo '<section class="aaspf-panel" id="quelle-' . esc_attr($source->id()) . '">';
            echo '<h2>' . esc_html($source->label()) . '</h2>';
            echo '<p class="description">' . esc_html($source->description()) . '</p>';
            $source->renderStartForm();
            echo '</section>';
        }
    }

    /**
     * The episode view: the current state at the top, below it exactly one box
     * with whatever is due now, and everything for reference in tabs.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderDetail(array $episode): void
    {
        $id = (int) $episode['id'];
        $stations = Workflow::stations($episode);
        $current = Workflow::current($stations);
        $source = \Sonoquill\Source\Sources::forEpisode($episode);
        $panelTitle = $source->panelTitle($episode);
        ob_start();
        $source->renderAside($episode);
        $aside = trim((string) ob_get_clean());

        echo '<p class="aaspf-zurueck"><a href="' . esc_url(Urls::episodes()) . '">&larr; '
            . esc_html__('All episodes', 'sonoquill') . '</a></p>';

        echo '<div class="aaspf-titelzeile">';
        echo '<h1>' . esc_html(Overview::title($episode)) . '</h1>';
        echo wp_kses(self::statusPill($episode), Html::allowed());
        echo '</div>';

        $meta = [];
        $meta[] = $source->label();
        if (($episode['source_filename'] ?? '') !== '') {
            $meta[] = (string) $episode['source_filename'];
        }
        /* translators: %s: creation date of the episode */
        $meta[] = sprintf(__('created on %s', 'sonoquill'), mysql2date('d.m.Y', (string) $episode['created_at']));
        $meta[] = (int) ($episode['auto_chain'] ?? 0) === 1
            ? __('continues automatically after approval', 'sonoquill')
            : __('every step by hand', 'sonoquill');
        echo '<p class="aaspf-meta">' . esc_html(implode(' · ', $meta)) . '</p>';

        self::renderResume($episode);
        self::renderSteps($stations, false);
        self::renderNow($episode, $current);
        self::renderFigures($episode);

        $tabs = ['skript' => __('Speech script', 'sonoquill')];
        if ($panelTitle !== '') {
            $tabs['quellen'] = $panelTitle;
        }
        $tabs['veroeffentlichung'] = __('Publishing', 'sonoquill');
        $tabs['audio'] = __('Audio', 'sonoquill');
        $tabs['verlauf'] = __('History and costs', 'sonoquill');

        $default = match ($current['key'] ?? '') {
            Workflow::QUELLEN => 'quellen',
            Workflow::SKRIPT, Workflow::TEXTFREIGABE => 'skript',
            Workflow::AUDIO, Workflow::AUPHONIC, Workflow::PODLOVE, Workflow::AUDIOFREIGABE => 'audio',
            default => 'veroeffentlichung',
        };

        echo '<div class="aaspf-reiter" data-aaspf-reiter data-standard="' . esc_attr($default) . '">';
        echo '<label class="aaspf-reiter-wahl-label" for="aaspf-reiter-wahl">' . esc_html__('Section', 'sonoquill') . '</label>';
        echo '<select id="aaspf-reiter-wahl" class="aaspf-reiter-wahl">';
        foreach ($tabs as $key => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($key, $default, false), esc_html($label));
        }
        echo '</select>';
        echo '<div class="aaspf-reiter-leiste" role="tablist" aria-label="' . esc_attr__('Episode sections', 'sonoquill') . '">';
        foreach ($tabs as $key => $label) {
            printf(
                '<a href="#reiter-%1$s" role="tab" id="tab-%1$s" aria-controls="reiter-%1$s" aria-selected="%2$s" class="aaspf-reiter-knopf">%3$s</a>',
                esc_attr($key),
                $key === $default ? 'true' : 'false',
                esc_html($label)
            );
        }
        echo '</div></div>';

        echo '<div class="aaspf-raster' . ($aside !== '' ? ' aaspf-raster-mit-seite' : '') . '">';
        echo '<div class="aaspf-raster-haupt">';

        self::panel('skript', static function () use ($id, $episode): void {
            $status = (string) $episode['status'];
            self::renderPipelineActions($id, $status, (string) ($episode['script_text'] ?? ''));
            self::renderGate($episode);
            self::renderFactCheck($episode);
            self::renderScriptForm($episode);
            if (trim((string) ($episode['script_text'] ?? '')) === '' && trim((string) ($episode['source_text'] ?? '')) !== '') {
                echo '<h3>' . esc_html__('Fact script', 'sonoquill') . '</h3>';
                echo '<pre class="aaspf-vorlage">' . esc_html((string) $episode['source_text']) . '</pre>';
            }
        });

        if ($panelTitle !== '') {
            self::panel('quellen', static function () use ($episode, $source): void {
                $source->renderPanel($episode);
            });
        }

        self::panel('veroeffentlichung', static function () use ($episode): void {
            self::renderMetadata($episode);
            self::renderProduction($episode);
            if (trim((string) ($episode['episode_title'] ?? '')) === '') {
                echo '<p class="description">' . esc_html__('Title, description and chapters are created together with the speech script.', 'sonoquill') . '</p>';
            }
        });

        self::panel('audio', static function () use ($episode): void {
            ob_start();
            self::renderAudio($episode);
            $html = (string) ob_get_clean();
            echo trim($html) !== ''
                ? wp_kses($html, Html::allowed())
                : '<p class="description">' . esc_html__('The audio is created after the text approval.', 'sonoquill') . '</p>';
        });

        self::panel('verlauf', static function () use ($id, $episode): void {
            self::renderCosts($episode);
            self::renderLog($id);
            self::renderDangerZone($id);
        });

        echo '</div>';

        if ($aside !== '') {
            echo '<aside class="aaspf-raster-seite">' . wp_kses($aside, Html::allowed()) . '</aside>';
        }

        echo '</div>';
    }

    private static function panel(string $key, callable $content): void
    {
        echo '<section class="aaspf-panel aaspf-reiter-panel" role="tabpanel" id="reiter-' . esc_attr($key) . '" aria-labelledby="tab-' . esc_attr($key) . '">';
        $content();
        echo '</section>';
    }

    /**
     * The step bar. On narrow screens it turns into a single line
     * "Step 3 of 7" with a progress bar.
     *
     * @param list<array{key:string,titel:string,zustand:string,wort:string}> $stations
     */
    public static function renderSteps(array $stations, bool $compact): void
    {
        $current = Workflow::current($stations);

        echo '<div class="aaspf-schritte-kurz" aria-hidden="true">';
        if ($current !== null) {
            printf(
                '<span><strong>%s</strong></span><span>%s</span>',
                /* translators: 1: current step number, 2: total number of steps, 3: step title */
                esc_html(sprintf(__('Step %1$d of %2$d · %3$s', 'sonoquill'), $current['nummer'], $current['gesamt'], $current['titel'])),
                esc_html($current['wort'])
            );
        } else {
            echo '<span><strong>' . esc_html__('All steps done', 'sonoquill') . '</strong></span>';
        }
        echo '<span class="aaspf-schritte-balken">';
        foreach ($stations as $station) {
            echo '<span data-zustand="' . esc_attr($station['zustand']) . '"></span>';
        }
        echo '</span></div>';

        echo '<ol class="aaspf-schritte' . ($compact ? ' aaspf-schritte-klein' : '') . '" aria-label="' . esc_attr__('Episode progress', 'sonoquill') . '">';
        foreach ($stations as $station) {
            echo '<li data-zustand="' . esc_attr($station['zustand']) . '"' . ($station['zustand'] === 'dran' ? ' aria-current="step"' : '') . '>';
            echo '<span class="aaspf-schritt-name">' . esc_html($station['titel']) . '</span>';
            echo '<span class="aaspf-schritt-wort">' . esc_html($station['wort']) . '</span>';
            echo '</li>';
        }
        echo '</ol>';
    }

    /**
     * The "Up next" box: exactly one next action.
     *
     * @param array<string,mixed>                                                                       $episode
     * @param array{key:string,titel:string,zustand:string,wort:string,nummer:int,gesamt:int}|null $current
     */
    private static function renderNow(array $episode, ?array $current): void
    {
        $id = (int) $episode['id'];
        $status = (string) $episode['status'];
        $auto = (int) ($episode['auto_chain'] ?? 0) === 1;

        if ($current === null) {
            echo '<section class="aaspf-jetzt aaspf-jetzt-fertig">';
            echo '<div class="aaspf-jetzt-inhalt">';
            echo '<h2>' . esc_html__('Done', 'sonoquill') . '</h2>';
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: date and time of the audio approval */
                __('Audio approved on %s. Publishing is done by hand in Podlove.', 'sonoquill'),
                mysql2date('d.m.Y, H:i', (string) ($episode['audio_approved_at'] ?? ''))
            )) . '</p>';
            echo '</div>';
            $postId = (int) ($episode['podlove_post_id'] ?? 0);
            if ($postId > 0 && get_post($postId) !== null) {
                echo '<div class="aaspf-jetzt-aktion"><a class="button button-primary button-hero" href="' . esc_url((string) get_edit_post_link($postId)) . '">'
                    . esc_html__('Open Podlove draft', 'sonoquill') . '</a></div>';
            }
            echo '</section>';

            return;
        }

        $human = Workflow::needsHuman($episode, $current['key']);

        echo '<section class="aaspf-jetzt' . ($human ? '' : ' aaspf-jetzt-laeuft') . '" aria-labelledby="aaspf-jetzt-titel"'
            . ($human ? '' : ' data-aaspf-live data-episode="' . esc_attr((string) $id) . '" data-key="' . esc_attr($current['key']) . '"')
            . '>';
        echo '<div class="aaspf-jetzt-inhalt">';
        echo '<p class="aaspf-jetzt-marke">' . esc_html(sprintf(
            /* translators: 1: current step number, 2: total number of steps */
            $human ? __('Up next · Step %1$d of %2$d', 'sonoquill') : __('Running · Step %1$d of %2$d', 'sonoquill'),
            $current['nummer'],
            $current['gesamt']
        )) . '</p>';

        $action = '';

        switch ($current['key']) {
            case Workflow::QUELLEN:
                if ($status === EpisodeStatus::SOURCE_FAILED) {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('A step got stuck', 'sonoquill') . '</h2>';
                    echo '<p>' . esc_html__('The reason is shown in the yellow box at the top and in the history. “Resume run” picks up again at the missing step.', 'sonoquill') . '</p>';
                } else {
                    [$headline, $text] = \Sonoquill\Source\Sources::forEpisode($episode)->runningNotice($episode);
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html($headline) . '</h2>';
                    echo '<p>' . esc_html($text) . '</p>';
                }
                break;

            case Workflow::SKRIPT:
                if (!$human) {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('The speech script is being written', 'sonoquill') . '</h2>';
                    echo '<p>' . esc_html__('Editing, number diff, fact check and publishing data run in the background, a few minutes in total.', 'sonoquill') . '</p>';
                } else {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('Create speech script', 'sonoquill') . '</h2>';
                    echo '<p>' . esc_html__('The fact script is turned into the speech script; then the number diff and fact check review it.', 'sonoquill') . '</p>';
                    $action = self::captureForm(EpisodeActions::ACTION_REDIGAT, $id, __('Start editing', 'sonoquill'));
                }
                break;

            case Workflow::TEXTFREIGABE:
                echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('Read and approve the text', 'sonoquill') . '</h2>';
                self::renderChecklist($episode);
                if ($status === EpisodeStatus::GATE_FAILED) {
                    $action = '<a class="button button-hero" href="#reiter-skript" data-aaspf-reiter-sprung="skript">' . esc_html__('Go to the open items', 'sonoquill') . '</a>'
                        . '<p class="aaspf-jetzt-hinweis">' . esc_html__('Locked until the open items have been fixed in the text or acknowledged.', 'sonoquill') . '</p>';
                } else {
                    $action = self::captureForm(EpisodeActions::ACTION_APPROVE, $id, __('Approve text', 'sonoquill'), true)
                        . '<p class="aaspf-jetzt-hinweis">' . esc_html($auto
                            ? __('After that, audio, Auphonic and Podlove run on their own. The audio approval request arrives by e-mail.', 'sonoquill')
                            : __('After that, you create the audio.', 'sonoquill')) . '</p>'
                        . '<a class="aaspf-jetzt-link" href="#reiter-skript" data-aaspf-reiter-sprung="skript">' . esc_html__('Read speech script', 'sonoquill') . '</a>';
                }
                break;

            case Workflow::AUDIO:
                $summary = SegmentRepository::summary($id);
                $ready = $summary['erzeugt'] + $summary['beanstandet'];
                if (!$human && $summary['gesamt'] > 0) {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('The audio is being created', 'sonoquill') . '</h2>';
                    echo '<p><span data-aaspf-live-zahl>' . esc_html(sprintf(
                        /* translators: 1: number of voiced segments, 2: total number of segments */
                        __('%1$d of %2$d', 'sonoquill'),
                        $ready,
                        $summary['gesamt']
                    )) . '</span> ' . esc_html__('segments voiced. Then assembly and Auphonic, all on their own.', 'sonoquill') . '</p>';
                    printf(
                        '<progress class="aaspf-fortschritt" data-aaspf-live-balken max="%1$d" value="%2$d">%3$s</progress>',
                        (int) max(1, $summary['gesamt']),
                        (int) $ready,
                        esc_html(sprintf(
                            /* translators: 1: number of voiced segments, 2: total number of segments */
                            __('%1$d of %2$d', 'sonoquill'),
                            $ready,
                            $summary['gesamt']
                        ))
                    );
                } else {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('Create audio', 'sonoquill') . '</h2>';
                    echo '<p>' . esc_html($summary['gesamt'] === 0
                        ? __('Each paragraph becomes a segment and is voiced with the voice clone.', 'sonoquill')
                        /* translators: 1: number of finished segments, 2: total number of segments */
                        : sprintf(__('%1$d of %2$d segments done. Missing ones will be created, then the assembly.', 'sonoquill'), $ready, $summary['gesamt'])) . '</p>';
                    $action = self::captureForm(
                        EpisodeActions::ACTION_AUDIO,
                        $id,
                        $summary['gesamt'] === 0 ? __('Create audio', 'sonoquill') : __('Create missing segments', 'sonoquill'),
                        true
                    );
                }
                break;

            case Workflow::AUPHONIC:
                if (!$human) {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('The episode is being sent to Auphonic', 'sonoquill') . '</h2>';
                    echo '<p>' . esc_html__('The assembly is done. Auphonic is now computing levels, loudness and chapter marks.', 'sonoquill') . '</p>';
                } else {
                    echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('Send to Auphonic', 'sonoquill') . '</h2>';
                    echo '<p>' . esc_html__('The raw assembly is done. You can listen to it in the Audio tab.', 'sonoquill') . '</p>';
                    ob_start();
                    self::renderProduceForm($episode);
                    $action = (string) ob_get_clean();
                }
                break;

            case Workflow::PODLOVE:
                echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('Auphonic is mastering the episode', 'sonoquill') . '</h2>';
                echo '<p>' . esc_html__('The callback then creates the Podlove draft. Nothing to do here.', 'sonoquill') . '</p>';
                $uuid = (string) ($episode['auphonic_production_uuid'] ?? '');
                if ($uuid !== '') {
                    echo '<div class="aaspf-jetzt-stand">';
                    self::renderAuphonicStand($episode, $uuid);
                    echo '</div>';
                }
                break;

            case Workflow::AUDIOFREIGABE:
                echo '<h2 id="aaspf-jetzt-titel">' . esc_html__('Listen to and approve the mastered version', 'sonoquill') . '</h2>';
                $slug = (string) ($episode['podlove_slug'] ?? '');
                if ($slug !== '' && file_exists(MediaStore::path($slug, 'mp3'))) {
                    echo '<audio class="aaspf-folge-player" controls preload="metadata" src="' . esc_url(MediaStore::url($slug, 'mp3')) . '"></audio>';
                }
                echo '<p class="description">' . esc_html__('Exactly as it will appear in the feed. Individual segments and the chapter jumps of the raw assembly are in the Audio tab.', 'sonoquill') . '</p>';
                $action = self::captureForm(EpisodeActions::ACTION_APPROVE_AUDIO, $id, __('Approve audio', 'sonoquill'), true)
                    . '<a class="button" href="#reiter-audio" data-aaspf-reiter-sprung="audio">' . esc_html__('Something sounds wrong', 'sonoquill') . '</a>'
                    . '<p class="aaspf-jetzt-hinweis">' . esc_html__('Publishing is then done by hand in Podlove.', 'sonoquill') . '</p>';
                break;
        }

        echo '</div>';

        if ($action !== '') {
            echo '<div class="aaspf-jetzt-aktion">' . wp_kses($action, Html::allowed()) . '</div>';
        }

        echo '</section>';
    }

    /**
     * A form with exactly one button, as a string — for the action column.
     */
    private static function captureForm(string $action, int $episodeId, string $label, bool $primary = true): string
    {
        ob_start();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $episodeId) . '">';
        echo '<button type="submit" class="button ' . ($primary ? 'button-primary ' : '') . 'button-hero">' . esc_html($label) . '</button>';
        echo '</form>';

        return (string) ob_get_clean();
    }

    /**
     * The checks before text approval, one line each with an indicator and a word.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderChecklist(array $episode): void
    {
        $acknowledged = EpisodeRepository::decodeList($episode['diff_acknowledged'] ?? null);
        $diff = EpisodeRepository::decodeMap($episode['diff_json'] ?? null);
        $items = [];

        $invented = count((array) ($diff['erfunden'] ?? []));
        $openDiff = 0;
        foreach (['fehlt', 'geaendert'] as $bucket) {
            foreach ((array) ($diff[$bucket] ?? []) as $finding) {
                if (!in_array((string) ($finding['key'] ?? ''), $acknowledged, true)) {
                    $openDiff++;
                }
            }
        }
        $guardBlocks = count(array_filter(
            EpisodeRepository::decodeList($episode['guard_json'] ?? null),
            static fn (array $g): bool => ($g['severity'] ?? '') === 'block'
        ));

        $items[] = $invented + $openDiff + $guardBlocks === 0
            ? ['ok', __('Number diff', 'sonoquill'), __('nothing invented, nothing missing', 'sonoquill')]
            /* translators: 1: number of invented numbers, 2: number of open discrepancies */
            : ['fail', __('Number diff', 'sonoquill'), sprintf(__('%1$d invented, %2$d open', 'sonoquill'), $invented, $openDiff + $guardBlocks)];

        if (($episode['factcheck_json'] ?? null) === null) {
            $items[] = ['warn', __('Fact check', 'sonoquill'), __('still running', 'sonoquill')];
        } else {
            $openFacts = array_diff(\Sonoquill\Pipeline\FactCheck::blockingKeys((int) $episode['id']), $acknowledged);
            $total = count(EpisodeRepository::decodeList($episode['factcheck_json']));
            $items[] = $openFacts === []
                /* translators: %d: number of fact check notes */
                ? ['ok', __('Fact check', 'sonoquill'), $total === 0 ? __('no findings', 'sonoquill') : sprintf(__('%d notes, none open', 'sonoquill'), $total)]
                /* translators: %d: number of open serious findings */
                : ['fail', __('Fact check', 'sonoquill'), sprintf(__('%d serious, open', 'sonoquill'), count($openFacts))];
        }

        foreach (\Sonoquill\Source\Sources::forEpisode($episode)->checklist($episode, $acknowledged) as $item) {
            $items[] = $item;
        }

        $minutes = \Sonoquill\Notify\Notifier::estimateMinutes(mb_strlen((string) ($episode['script_text'] ?? '')));
        $items[] = $minutes > 30
            /* translators: %d: estimated length in minutes */
            ? ['warn', __('Length', 'sonoquill'), sprintf(__('about %d instead of at most 30 minutes', 'sonoquill'), $minutes)]
            /* translators: %d: estimated length in minutes */
            : ['ok', __('Length', 'sonoquill'), sprintf(__('about %d minutes', 'sonoquill'), $minutes)];

        echo '<ul class="aaspf-pruefliste">';
        foreach ($items as [$tone, $label, $text]) {
            echo '<li><span class="aaspf-status aaspf-status-' . esc_attr($tone) . '">' . esc_html($label) . '</span> ' . esc_html($text) . '</li>';
        }
        echo '</ul>';
    }

    /**
     * The key figures below the "Up next" box.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderFigures(array $episode): void
    {
        $script = (string) ($episode['script_text'] ?? '');
        $chars = mb_strlen($script);
        $duration = (int) ($episode['duration_ms'] ?? 0);

        echo '<div class="aaspf-kennzahlen">';

        if ($duration > 0) {
            self::figure(__('Length', 'sonoquill'), Synthesis::formatDuration($duration), __('measured from the assembly', 'sonoquill'));
        } elseif ($chars > 0) {
            $minutes = \Sonoquill\Notify\Notifier::estimateMinutes($chars);
            self::figure(
                __('Speech script', 'sonoquill'),
                sprintf('≈ %d min', $minutes),
                /* translators: %s: number of characters in the speech script */
                sprintf(__('%s characters', 'sonoquill'), number_format_i18n($chars)),
                $minutes > 30 ? 'warn' : ''
            );
        } else {
            self::figure(__('Speech script', 'sonoquill'), '—', __('not written yet', 'sonoquill'));
        }

        if (trim($script) === '') {
            self::figure(__('Checks', 'sonoquill'), '—', __('after editing', 'sonoquill'));
        } else {
            $acknowledged = EpisodeRepository::decodeList($episode['diff_acknowledged'] ?? null);
            $open = count(array_diff(Gate::acknowledgeableKeys((int) $episode['id']), $acknowledged))
                + count((array) (EpisodeRepository::decodeMap($episode['diff_json'] ?? null)['erfunden'] ?? []));
            self::figure(
                __('Checks', 'sonoquill'),
                /* translators: %d: number of open check items */
                sprintf(_n('%d open', '%d open', $open, 'sonoquill'), $open),
                $open === 0 ? __('Numbers, facts and sources are clean', 'sonoquill') : __('in the Speech script tab', 'sonoquill'),
                $open === 0 ? 'ok' : 'warn'
            );
        }

        $sourceFigure = \Sonoquill\Source\Sources::forEpisode($episode)->figure($episode);
        if ($sourceFigure !== null) {
            self::figure($sourceFigure[0], $sourceFigure[1], $sourceFigure[2]);
        } else {
            self::figure(
                __('Fact script', 'sonoquill'),
                number_format_i18n(mb_strlen((string) ($episode['source_text'] ?? ''))),
                __('characters of source text', 'sonoquill')
            );
        }

        $billed = (int) ($episode['chars_billed'] ?? 0);
        self::figure(
            __('Costs so far', 'sonoquill'),
            number_format_i18n((float) $episode['cost_cents'] / 100, 2) . ' $',
            $billed > 0
                /* translators: %s: number of billed ElevenLabs characters */
                ? sprintf(__('Anthropic estimated · %s ElevenLabs characters', 'sonoquill'), number_format_i18n($billed))
                : __('Anthropic, estimated', 'sonoquill')
        );

        echo '</div>';
    }

    private static function figure(string $label, string $value, string $sub, string $tone = ''): void
    {
        printf(
            '<div class="aaspf-kennzahl%s"><div class="aaspf-kennzahl-titel">%s</div><div class="aaspf-kennzahl-wert">%s</div><div class="aaspf-kennzahl-unter">%s</div></div>',
            $tone !== '' ? ' aaspf-kennzahl-' . esc_attr($tone) : '',
            esc_html($label),
            esc_html($value),
            esc_html($sub)
        );
    }

    /**
     * State as a word in a coloured pill. If the episode is waiting for a
     * human, the pill says for what; if it is running, it says what is running.
     *
     * @param array<string,mixed> $episode
     */
    public static function statusPill(array $episode): string
    {
        $status = (string) $episode['status'];

        if (in_array($status, [EpisodeStatus::SOURCE_FAILED, EpisodeStatus::REDIGAT_FAILED], true)) {
            [$tone, $label] = ['fail', EpisodeStatus::label($status)];
        } elseif ($status === EpisodeStatus::DONE) {
            [$tone, $label] = ['ok', EpisodeStatus::label($status)];
        } else {
            $current = Workflow::current(Workflow::stations($episode));
            if ($current === null) {
                [$tone, $label] = ['ok', EpisodeStatus::label($status)];
            } elseif (Workflow::needsHuman($episode, $current['key'])) {
                /* translators: %s: title of the current step */
                [$tone, $label] = [$status === EpisodeStatus::GATE_FAILED ? 'fail' : 'warn', sprintf(__('Waiting: %s', 'sonoquill'), $current['titel'])];
            } else {
                /* translators: %s: title of the current step */
                [$tone, $label] = ['laeuft', sprintf(__('%s running', 'sonoquill'), $current['titel'])];
            }
        }

        return '<span class="aaspf-pille aaspf-pille-' . esc_attr($tone) . '">' . esc_html($label) . '</span>';
    }

    /**
     * One sentence for the overview: what happens next.
     *
     * @param array<string,mixed> $episode
     */
    public static function nextHint(array $episode, string $key): string
    {
        $auto = (int) ($episode['auto_chain'] ?? 0) === 1;

        return match ($key) {
            /* translators: %s: name of the text source */
            Workflow::QUELLEN       => sprintf(__('%s: The text is being created in the background. You will get an e-mail when it is ready.', 'sonoquill'), \Sonoquill\Source\Sources::forEpisode($episode)->label()),
            Workflow::SKRIPT        => __('The speech script is being written or is waiting for editing to start.', 'sonoquill'),
            Workflow::TEXTFREIGABE  => __('The text is waiting for your approval.', 'sonoquill'),
            Workflow::AUDIO         => $auto ? __('The audio is being created. Assembly and Auphonic then run on their own; you will get an e-mail when the Podlove draft is ready.', 'sonoquill') : __('The audio is waiting to be created.', 'sonoquill'),
            Workflow::AUPHONIC      => $auto ? __('The episode is being sent to Auphonic.', 'sonoquill') : __('The raw assembly is waiting to be sent to Auphonic.', 'sonoquill'),
            Workflow::PODLOVE       => __('Auphonic is mastering the episode; the callback creates the Podlove draft.', 'sonoquill'),
            Workflow::AUDIOFREIGABE => __('The mastered version is waiting for your approval.', 'sonoquill'),
            default                 => '',
        };
    }

    /**
     * Length for tables: measured if there is an assembly, otherwise estimated.
     *
     * @param array<string,mixed> $episode
     */
    public static function lengthLabel(array $episode): string
    {
        $duration = (int) ($episode['duration_ms'] ?? 0);
        if ($duration > 0) {
            return Synthesis::formatDuration($duration);
        }

        $chars = mb_strlen((string) ($episode['script_text'] ?? ''));

        return $chars > 0 ? sprintf('≈ %d min', \Sonoquill\Notify\Notifier::estimateMinutes($chars)) : '—';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderResume(array $episode): void
    {
        $reason = \Sonoquill\Pipeline\Recovery::stuckReason($episode);
        if ($reason === null) {
            return;
        }

        echo '<div class="notice notice-warning inline"><p><strong>'
            . esc_html__('Something is stuck here.', 'sonoquill') . '</strong> ' . esc_html($reason) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_RESUME);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_RESUME) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) (int) $episode['id']) . '">';
        echo '<p><button type="submit" class="aaspf-knopf aaspf-knopf-haupt">'
            . esc_html__('Resume run', 'sonoquill') . '</button></p>';
        echo '<p class="description">' . esc_html__('Picks up where something is missing. Segments that were already created are kept and cost nothing again.', 'sonoquill') . '</p>';
        echo '</form></div>';
    }

    private static function renderPipelineActions(int $id, string $status, string $script): void
    {
        $episode = EpisodeRepository::find($id) ?? [];
        if ($episode !== [] && \Sonoquill\Source\Sources::forEpisode($episode)->preparesText() && trim((string) ($episode['source_text'] ?? '')) === '') {
            // As long as the source is still writing its fact script, there is
            // nothing to edit.
            return;
        }

        if ($status === EpisodeStatus::REDIGAT_RUNNING) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Editing is running in the background. Reload the page now and then.', 'sonoquill') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_REDIGAT);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_REDIGAT) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';

        $label = $script === ''
            ? __('Start editing', 'sonoquill')
            : __('Repeat editing', 'sonoquill');

        echo '<p>';
        submit_button($label, 'primary', 'submit', false);
        echo '</p>';

        if ($script !== '') {
            echo '<p class="description">' . esc_html__('Repeating overwrites the speech script and costs tokens again.', 'sonoquill') . '</p>';
        }

        echo '</form>';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderGate(array $episode): void
    {
        $script = (string) ($episode['script_text'] ?? '');
        if (trim($script) === '') {
            return;
        }

        echo '<h3>' . esc_html__('Number diff', 'sonoquill') . '</h3>';

        $guard = EpisodeRepository::decodeList($episode['guard_json'] ?? null);
        foreach ($guard as $problem) {
            $blocking = ($problem['severity'] ?? '') === 'block';
            echo '<div class="notice notice-' . ($blocking ? 'error' : 'warning') . ' inline"><p><strong>';
            echo esc_html((string) ($problem['message'] ?? ''));
            echo '</strong></p>';
            foreach ((array) ($problem['samples'] ?? []) as $sample) {
                echo '<p><code>' . esc_html((string) $sample) . '</code></p>';
            }
            echo '</div>';
        }

        $diff = EpisodeRepository::decodeMap($episode['diff_json'] ?? null);

        self::renderBucket(
            __('Invented numbers', 'sonoquill'),
            __('Present in the speech script but not in the source text. This discrepancy cannot be acknowledged — it must be fixed in the text.', 'sonoquill'),
            (array) ($diff['erfunden'] ?? []),
            false,
            []
        );

        $acknowledged = EpisodeRepository::decodeList($episode['diff_acknowledged'] ?? null);

        self::renderBucket(
            __('Changed numbers', 'sonoquill'),
            __('Rounded quantities. Acknowledge them if the rounding is intended.', 'sonoquill'),
            (array) ($diff['geaendert'] ?? []),
            true,
            $acknowledged
        );

        self::renderBucket(
            __('Missing numbers', 'sonoquill'),
            __('Present in the source text but not in the speech script.', 'sonoquill'),
            (array) ($diff['fehlt'] ?? []),
            true,
            $acknowledged
        );

        self::renderBucket(
            __('Differing frequency', 'sonoquill'),
            __('Present on both sides, just a different number of times. Does not block — the mandatory intro mentions the month in addition.', 'sonoquill'),
            (array) ($diff['haeufigkeit'] ?? []),
            false,
            []
        );

        if ($diff !== [] && ($diff['erfunden'] ?? []) === [] && ($diff['fehlt'] ?? []) === []
            && ($diff['geaendert'] ?? []) === [] && ($diff['haeufigkeit'] ?? []) === []) {
            echo '<p class="aaspf-status aaspf-status-ok">' . esc_html__('All numbers match.', 'sonoquill') . '</p>';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param list<string>                   $acknowledged
     */
    private static function renderBucket(string $title, string $description, array $findings, bool $acknowledgeable, array $acknowledged): void
    {
        if ($findings === []) {
            return;
        }

        echo '<h4>' . esc_html($title) . ' <span class="aaspf-badge aaspf-badge-skip">' . esc_html((string) count($findings)) . '</span></h4>';
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Value', 'sonoquill') . '</th>';
        echo '<th scope="col">' . esc_html__('Fact script', 'sonoquill') . '</th>';
        echo '<th scope="col">' . esc_html__('Script', 'sonoquill') . '</th>';
        echo '<th scope="col">' . esc_html__('Location', 'sonoquill') . '</th>';
        if ($acknowledgeable) {
            echo '<th scope="col">' . esc_html__('acknowledged', 'sonoquill') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($findings as $finding) {
            $key = (string) ($finding['key'] ?? '');
            $counterpart = (string) ($finding['counterpart'] ?? '');

            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($finding['label'] ?? '')) . '</strong>';
            if ($counterpart !== '') {
                /* translators: %s: the corresponding value on the other side */
                echo '<br><span class="description">' . esc_html(sprintf(__('instead of %s', 'sonoquill'), $counterpart)) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html((string) ($finding['sourceCount'] ?? 0)) . '×</td>';
            echo '<td>' . esc_html((string) ($finding['scriptCount'] ?? 0)) . '×</td>';
            echo '<td><code>' . esc_html((string) ($finding['context'] ?? '')) . '</code></td>';

            if ($acknowledgeable) {
                echo '<td><input type="checkbox" form="aaspf-script-form" name="acknowledged[]" value="' . esc_attr($key) . '"';
                echo checked(in_array($key, $acknowledged, true), true, false) . '></td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * The content review, run after the number diff.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderFactCheck(array $episode): void
    {
        $findings = EpisodeRepository::decodeList($episode['factcheck_json'] ?? null);
        if (trim((string) ($episode['script_text'] ?? '')) === '') {
            return;
        }

        echo '<h3>' . esc_html__('Fact check', 'sonoquill') . '</h3>';

        if ($findings === []) {
            echo '<p class="aaspf-status aaspf-status-ok">' . esc_html__('No content discrepancies found.', 'sonoquill') . '</p>';

            return;
        }

        echo '<p class="description">' . esc_html__('This check comes from a model and does not block on its own. Serious findings, however, must be acknowledged before the text can be approved.', 'sonoquill') . '</p>';

        $acknowledged = EpisodeRepository::decodeList($episode['diff_acknowledged'] ?? null);

        echo '<table class="widefat striped"><thead><tr>';
        foreach ([
            __('Severity', 'sonoquill'),
            __('Type', 'sonoquill'),
            __('In the source', 'sonoquill'),
            __('In the speech script', 'sonoquill'),
            __('acknowledged', 'sonoquill'),
        ] as $head) {
            echo '<th scope="col">' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($findings as $index => $finding) {
            $severity = (string) ($finding['schwere'] ?? '');
            $blocking = $severity === 'hoch';
            $key = 'fakt:' . $index;

            $class = match ($severity) {
                'hoch'   => 'aaspf-status-fail',
                'mittel' => 'aaspf-status-warn',
                default  => 'aaspf-status-skip',
            };

            echo '<tr>';
            echo '<td><span class="aaspf-status ' . esc_attr($class) . '">' . esc_html($severity) . '</span></td>';
            echo '<td>' . esc_html((string) ($finding['art'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($finding['vorlage'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($finding['skript'] ?? ''));
            if (($finding['begruendung'] ?? '') !== '') {
                echo '<p class="description">' . esc_html((string) $finding['begruendung']) . '</p>';
            }
            echo '</td>';
            echo '<td>';
            if ($blocking) {
                echo '<input type="checkbox" form="aaspf-script-form" name="acknowledged[]" value="' . esc_attr($key) . '"'
                    . checked(in_array($key, $acknowledged, true), true, false) . '>';
            } else {
                echo '<span class="aaspf-segment-meta">' . esc_html__('not needed', 'sonoquill') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderScriptForm(array $episode): void
    {
        $id = (int) $episode['id'];
        $script = (string) ($episode['script_text'] ?? '');

        if (trim($script) === '' && $episode['status'] !== EpisodeStatus::GATE_FAILED) {
            return;
        }

        echo '<h3>' . esc_html__('Edit text', 'sonoquill') . '</h3>';
        echo '<form id="aaspf-script-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_SAVE_SCRIPT);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_SAVE_SCRIPT) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<textarea name="script_text" rows="24" class="large-text code" spellcheck="true">' . esc_textarea($script) . '</textarea>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %d: number of characters */
            __('%d characters. The number diff is recalculated after saving.', 'sonoquill'),
            mb_strlen($script)
        )) . '</p>';
        echo '<p>';
        submit_button(__('Save and check speech script', 'sonoquill'), 'secondary', 'submit', false);
        echo '</p>';
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderApproval(array $episode): void
    {
        $id = (int) $episode['id'];
        $status = (string) $episode['status'];

        if ($status === EpisodeStatus::TEXT_APPROVED) {
            echo '<div class="notice notice-success inline"><p><strong>';
            echo esc_html(sprintf(
                /* translators: %s: date and time of the text approval */
                __('Text approved on %s.', 'sonoquill'),
                (string) ($episode['text_approved_at'] ?? '')
            ));
            echo '</strong></p></div>';

            return;
        }

        $blocked = $status === EpisodeStatus::GATE_FAILED;


        if ($blocked) {
            echo '<p class="aaspf-status aaspf-status-fail">' . esc_html__('Locked while discrepancies are open. Correct the text, or acknowledge the overridable discrepancies and save.', 'sonoquill') . '</p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_APPROVE);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_APPROVE) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<p>';
        submit_button(__('Approve text', 'sonoquill'), 'primary', 'submit', false);
        echo '</p>';
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderMetadata(array $episode): void
    {
        if (trim((string) ($episode['episode_title'] ?? '')) === '') {
            return;
        }

        echo '<table class="widefat striped"><tbody>';

        $short = (string) ($episode['description_short'] ?? '');
        self::row(__('Title', 'sonoquill'), (string) $episode['episode_title']);
        self::row(
            __('Short description', 'sonoquill'),
            /* translators: %d: number of characters of the short description */
            $short . sprintf(__(' (%d characters)', 'sonoquill'), mb_strlen($short))
        );
        self::row(__('Description', 'sonoquill'), (string) ($episode['description_long'] ?? ''));

        $keywords = EpisodeRepository::decodeList($episode['keywords'] ?? null);
        self::row(__('Keywords', 'sonoquill'), implode(', ', array_map('strval', $keywords)));

        $chapters = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        $lines = [];
        foreach ($chapters as $chapter) {
            $lines[] = sprintf('%d. %s', (int) ($chapter['abschnitt'] ?? 0), (string) ($chapter['titel'] ?? ''));
        }
        self::row(__('Chapters', 'sonoquill'), implode(' · ', $lines));

        $disclosure = trim((string) ($episode['ai_disclosure_text'] ?? ''));
        if ($disclosure !== '') {
            echo '<tr><th scope="row">' . esc_html__('Disclosure', 'sonoquill') . '</th><td>';
            echo '<em>' . nl2br(esc_html($disclosure)) . '</em>';
            echo '<p class="description">' . esc_html__('Appears at the end of the show notes. Fixed for this episode; later changes in the settings only affect new episodes.', 'sonoquill') . '</p>';
            echo '</td></tr>';
        }

        $added = EpisodeRepository::decodeList($episode['dictionary_added'] ?? null);
        if ($added !== []) {
            echo '<tr><th scope="row">' . esc_html__('Dictionary extended', 'sonoquill') . '</th><td>';
            echo '<ul style="margin:0">';
            foreach ($added as $rule) {
                printf(
                    '<li><strong>%s</strong> &rarr; %s <span class="description">%s</span></li>',
                    esc_html((string) ($rule['begriff'] ?? '')),
                    esc_html((string) ($rule['aussprache'] ?? '')),
                    esc_html((string) ($rule['begruendung'] ?? ''))
                );
            }
            echo '</ul>';
            echo '<p class="description">' . esc_html__('Created automatically and bound as a new dictionary version before synthesis runs.', 'sonoquill') . '</p>';
            echo '</td></tr>';
        }

        $candidates = EpisodeRepository::decodeList($episode['pronunciation_candidates'] ?? null);
        if ($candidates !== []) {
            echo '<tr><th scope="row">' . esc_html__('Check pronunciation', 'sonoquill') . '</th><td>';
            echo esc_html(implode(', ', array_map('strval', $candidates)));
            echo '<p class="description">' . esc_html__('These terms are not in the dictionary and might sound wrong. Creating a rule now is cheaper than re-recording them later.', 'sonoquill') . '</p>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderAudio(array $episode): void
    {
        $id = (int) $episode['id'];
        $status = (string) $episode['status'];

        $segments = SegmentRepository::forEpisode($id);
        $summary = SegmentRepository::summary($id);

        // Segments that have already been created stay visible, whatever state
        // the episode is currently in. Only creating them depends on text approval.
        if ($segments === [] && $status !== EpisodeStatus::TEXT_APPROVED) {
            return;
        }


        if ($status !== EpisodeStatus::TEXT_APPROVED && $segments === []) {
            echo '<p class="description">' . esc_html__('The audio is only created after the text approval.', 'sonoquill') . '</p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_AUDIO);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_AUDIO) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<p>';
        submit_button(
            $segments === []
                ? __('Create audio', 'sonoquill')
                : __('Create missing segments', 'sonoquill'),
            'primary',
            'submit',
            false
        );
        echo '</p>';
        if ($segments !== []) {
            echo '<p class="description">' . esc_html__('Unchanged segments keep their audio and cost nothing. Only what has changed is created.', 'sonoquill') . '</p>';
        }
        echo '</form>';

        if ($segments === []) {
            return;
        }

        echo '<p>' . esc_html(sprintf(
            /* translators: 1: finished segments, 2: total, 3: duration */
            __('%1$d of %2$d segments done, %3$s in total.', 'sonoquill'),
            $summary['erzeugt'] + $summary['beanstandet'],
            $summary['gesamt'],
            Synthesis::formatDuration($summary['dauer_ms'])
        ));
        if ($summary['offen'] > 0) {
            echo ' <span class="aaspf-status aaspf-status-warn">' . esc_html__('Synthesis is still running.', 'sonoquill') . '</span>';
        }
        echo '</p>';

        self::renderMix($episode);
        self::renderSegmentTable($id, $segments);
    }

    /**
     * The second human gate: listening to the whole episode.
     *
     * There are two approvals, neither of them optional. This one is
     * deliberately placed above the segment list — whoever signs off the
     * episode wants to hear it in one go, not as twenty-five separate pieces.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderMix(array $episode): void
    {
        $id = (int) $episode['id'];
        $mix = (string) ($episode['mixed_audio_path'] ?? '');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        wp_nonce_field(EpisodeActions::ACTION_MONTAGE);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_MONTAGE) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<button type="submit" class="aaspf-knopf">' . esc_html__('Rebuild assembly', 'sonoquill') . '</button>';
        echo '</form>';

        if ($mix === '' || !EpisodeStorage::exists($mix)) {
            return;
        }

        $status = (string) $episode['status'];
        $chapters = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        $withTimes = array_values(array_filter($chapters, static fn ($c): bool => isset($c['start_ms'])));

        echo '<div class="aaspf-abnahme">';
        echo '<h3>' . esc_html__('Listen to the whole episode', 'sonoquill') . '</h3>';

        echo '<audio id="aaspf-folge" class="aaspf-folge-player" controls preload="metadata" src="'
            . esc_url(AudioStream::episodeUrl($id)) . '"></audio>';

        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: duration, 2: file size */
            __('%1$s, %2$s. The file is stored outside the web root and is only delivered after a permission check.', 'sonoquill'),
            Synthesis::formatDuration((int) ($episode['duration_ms'] ?? 0)),
            size_format(EpisodeStorage::size($mix))
        )) . '</p>';

        if ($withTimes !== []) {
            echo '<h4>' . esc_html__('Chapters — click to jump', 'sonoquill') . '</h4>';
            echo '<ul class="aaspf-kapitel">';
            foreach ($withTimes as $chapter) {
                $ms = (int) $chapter['start_ms'];
                printf(
                    '<li><button type="button" class="aaspf-sprung" data-aaspf="sprung" data-sekunde="%s"><code>%s</code> %s</button></li>',
                    esc_attr((string) round($ms / 1000, 3)),
                    esc_html(Transcript::timecode($ms)),
                    esc_html((string) ($chapter['titel'] ?? ''))
                );
            }
            echo '</ul>';
            echo '<p class="description">' . esc_html__('When jumping, the sentence should be heard from its start, not from the middle.', 'sonoquill') . '</p>';
        }

        $vtt = EpisodeStorage::mixRelativePath($id, 'vtt');
        if (EpisodeStorage::exists($vtt)) {
            echo '<p>' . esc_html(sprintf(
                /* translators: %s: file size */
                __('A transcript is available (%s), generated from the synthesis timestamps.', 'sonoquill'),
                size_format(EpisodeStorage::size($vtt))
            )) . '</p>';
        }

        self::renderMixNextStep($episode);
        echo '</div>';
    }

    /**
     * What comes after the raw assembly.
     *
     * The approval button used to be here. It does not belong here: the audio
     * approval reviews the mastered version, not the raw assembly. With both
     * buttons side by side, the view gave no indication which one came first.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderMixNextStep(array $episode): void
    {
        $status = (string) $episode['status'];

        if ($status === EpisodeStatus::DONE) {
            return;
        }

        echo '<p class="description">';
        if ($status === EpisodeStatus::AWAITING_AUDIO) {
            echo esc_html__('This is the raw assembly. Auphonic has mastered it in the meantime — the finished version is approved further down.', 'sonoquill');
        } elseif ($status === EpisodeStatus::PRODUCING) {
            echo esc_html__('This is the raw assembly. Auphonic is processing it right now; the approval comes afterwards, further down.', 'sonoquill');
        } else {
            echo esc_html__('This is the raw assembly, still without levelling and loudness. If it sounds right, send it to Auphonic further down. Approval only comes after that.', 'sonoquill');
        }
        echo '</p>';
    }

    /**
     * The second human gate (audio approval).
     *
     * The version that gets approved is the one that is published — the one
     * mastered by Auphonic, not the raw assembly. As long as it is not there
     * yet, this shows the reason instead of a button.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderAudioApproval(array $episode): void
    {
        $id = (int) $episode['id'];
        $status = (string) $episode['status'];

        echo '<h3>' . esc_html__('Audio approval', 'sonoquill') . '</h3>';

        if ($status === EpisodeStatus::DONE) {
            echo '<p class="aaspf-status aaspf-status-ok">' . esc_html(sprintf(
                /* translators: %s: point in time */
                __('Audio approved on %s. Publishing is done by hand in Podlove.', 'sonoquill'),
                (string) ($episode['audio_approved_at'] ?? '')
            )) . '</p>';

            return;
        }

        if ($status !== EpisodeStatus::AWAITING_AUDIO) {
            echo '<p class="description">' . esc_html(
                $status === EpisodeStatus::PRODUCING
                    ? __('Auphonic is still processing. As soon as the callback arrives, the approval button appears here.', 'sonoquill')
                    : __('Send to Auphonic first. The mastered version is approved, not the raw assembly.', 'sonoquill')
            ) . '</p>';

            return;
        }

        $slug = (string) ($episode['podlove_slug'] ?? '');
        if ($slug !== '' && file_exists(MediaStore::path($slug, 'mp3'))) {
            echo '<p>' . esc_html__('The finished version, exactly as it appears in the feed:', 'sonoquill') . '</p>';
            echo '<audio class="aaspf-folge-player" controls preload="metadata" src="'
                . esc_url(MediaStore::url($slug, 'mp3')) . '"></audio>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_APPROVE_AUDIO);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_APPROVE_AUDIO) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<p><button type="submit" class="aaspf-knopf aaspf-knopf-haupt">'
            . esc_html__('Approve audio', 'sonoquill') . '</button></p>';
        echo '<p class="description">' . esc_html__('Records that the episode has been listened to and found good. This does not publish anything — that is done by hand in Podlove.', 'sonoquill') . '</p>';
        echo '</form>';
    }

    /**
     * @param list<array<string,mixed>> $segments
     */
    private static function renderSegmentTable(int $id, array $segments): void
    {
        $flagged = 0;
        foreach ($segments as $segment) {
            if ((string) $segment['status'] === SegmentStatus::FLAGGED) {
                $flagged++;
            }
        }

        // Collapsed, so the review of the whole episode keeps its space at the top.
        // Flagged segments expand the list automatically.
        echo '<details class="aaspf-segmentliste"' . ($flagged > 0 ? ' open' : '') . '>';
        echo '<summary><strong>' . esc_html(sprintf(
            /* translators: %d: number of segments */
            __('Listen to individual segments (%d)', 'sonoquill'),
            count($segments)
        )) . '</strong>';
        if ($flagged > 0) {
            echo ' <span class="aaspf-badge aaspf-badge-warn">' . esc_html(sprintf(
                /* translators: %d: number of flagged segments */
                _n('%d flagged', '%d flagged', $flagged, 'sonoquill'),
                $flagged
            )) . '</span>';
        }
        echo '</summary>';
        echo '<p class="description">' . esc_html__('Below each segment there is “Correct pronunciation" — behind it are both options: a dictionary rule for every future episode, or re-speaking the sentence yourself (Voice Changer) for this one episode only. “Flag" additionally marks the segment so it does not get lost while going through.', 'sonoquill') . '</p>';

        foreach ($segments as $segment) {
            self::renderSegment($id, $segment);
        }

        echo '</details>';
    }

    /**
     * @param array<string,mixed> $segment
     */
    private static function renderSegment(int $episodeId, array $segment): void
    {
        $segmentId = (int) $segment['id'];
        $status = (string) $segment['status'];
        $flagged = $status === SegmentStatus::FLAGGED;
        $title = trim((string) $segment['chapter_title']);
        $hasAudio = EpisodeStorage::exists((string) $segment['audio_path']);

        echo '<div class="aaspf-segment" data-status="' . esc_attr($status) . '">';

        echo '<div class="aaspf-segment-kopf">';
        echo '<span class="aaspf-segment-nr">' . esc_html((string) (int) $segment['idx']) . '</span>';
        if ($title !== '') {
            echo '<span class="aaspf-segment-kapitel">' . esc_html($title) . '</span>';
        }
        echo '<span class="aaspf-segment-meta">';
        echo esc_html(Synthesis::formatDuration((int) $segment['duration_ms']));
        echo ' · ' . esc_html(SegmentStatus::labels()[$status] ?? $status);
        if ((string) $segment['source'] === 'sts') {
            echo ' · ' . esc_html__('re-spoken', 'sonoquill');
        }
        echo '</span>';
        echo '</div>';

        echo '<p class="aaspf-segment-text">' . esc_html((string) $segment['text']) . '</p>';

        if ($hasAudio) {
            echo '<audio controls preload="none" data-aaspf="segment-audio" src="'
                . esc_url(AudioStream::segmentUrl($segmentId)) . '"></audio>';
        } else {
            echo '<p class="aaspf-status aaspf-status-pending">' . esc_html__('not created yet', 'sonoquill') . '</p>';
        }

        if ($flagged && (string) $segment['note'] !== '') {
            echo '<p class="aaspf-status aaspf-status-warn">' . esc_html((string) $segment['note']) . '</p>';
        }

        echo '<div class="aaspf-aktionen">';
        self::actionButton(
            $episodeId,
            $segmentId,
            EpisodeActions::ACTION_FLAG,
            $flagged ? __('all good', 'sonoquill') : __('flag', 'sonoquill'),
            $flagged ? '' : 'aaspf-knopf-warnung',
            !$flagged
        );
        self::actionButton(
            $episodeId,
            $segmentId,
            EpisodeActions::ACTION_REGENERATE,
            __('regenerate', 'sonoquill')
        );
        echo '</div>';

        // The correction area used to depend on flagging. Both options were
        // therefore only visible after clicking "flag" — which is why nobody
        // ever found the re-speaking option. It now sits on every segment and
        // already names both options in the expandable summary line.
        echo '<details class="aaspf-korrektur-auf"' . ($flagged ? ' open' : '') . '>';
        echo '<summary>' . esc_html__('Correct pronunciation — create a rule or re-speak the sentence yourself', 'sonoquill') . '</summary>';
        self::renderCorrection($segment);
        echo '</details>';

        echo '</div>';
    }

    private static function actionButton(int $episodeId, int $segmentId, string $action, string $label, string $extraClass = '', bool $withNote = false): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($action);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $episodeId) . '">';
        echo '<input type="hidden" name="segment" value="' . esc_attr((string) $segmentId) . '">';
        if ($withNote) {
            echo '<input type="text" name="note" class="aaspf-feld-notiz" placeholder="'
                . esc_attr__('what sounded wrong?', 'sonoquill') . '">';
        }
        echo '<button type="submit" class="aaspf-knopf ' . esc_attr($extraClass) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    /**
     * The correction area shows both options, the dictionary rule first.
     *
     * The order is deliberate and has a reason: for a recurring
     * proper name, re-speaking fixes the problem in this one episode and in
     * no other.
     *
     * @param array<string,mixed> $segment
     */
    private static function renderCorrection(array $segment): void
    {
        $segmentId = (int) $segment['id'];
        $note = trim((string) $segment['note']);
        // If the note consists of a single word, it is very likely the term
        // that sounded wrong.
        $guess = preg_match('/^\p{L}[\p{L}\p{N}\-]*$/u', $note) === 1 ? $note : '';

        echo '<div class="aaspf-korrektur" data-segment="' . esc_attr((string) $segmentId) . '">';

        echo '<div class="aaspf-weg">';
        echo '<h4><span class="aaspf-weg-nr">1</span>' . esc_html__('Dictionary rule — applies to every future episode', 'sonoquill') . '</h4>';
        echo '<p class="description">' . esc_html__('The right option for a recurring proper name. The phonetic transcription states exactly what should be spoken — the ˈ mark comes before the stressed syllable, ː lengthens the preceding vowel.', 'sonoquill') . '</p>';

        // If the dictionary already has a rule for the guessed term, it gets
        // replaced, not duplicated. That should be made visible.
        $currentRule = $guess === '' ? '' : self::existingRule($guess);
        if ($currentRule !== '') {
            echo '<p class="aaspf-status aaspf-status-warn">' . esc_html(sprintf(
                /* translators: 1: term, 2: existing pronunciation */
                __('There is already the rule "%2$s" for "%1$s". It will be replaced, not duplicated.', 'sonoquill'),
                $guess,
                $currentRule
            )) . '</p>';
        }

        $art = \Sonoquill\Voice\DictionaryWriter::preferredRuleType();

        echo '<div class="aaspf-felder">';
        echo '<div><label for="aaspf-begriff-' . esc_attr((string) $segmentId) . '">' . esc_html__('Term in the text', 'sonoquill') . '</label>';
        echo '<input type="text" id="aaspf-begriff-' . esc_attr((string) $segmentId) . '" data-aaspf="regel-begriff" value="' . esc_attr($guess) . '" placeholder="Fomalhaut"></div>';
        echo '<div><label for="aaspf-ipa-' . esc_attr((string) $segmentId) . '">' . esc_html__('Phonetic transcription (IPA)', 'sonoquill') . '</label>';
        echo '<input type="text" id="aaspf-ipa-' . esc_attr((string) $segmentId) . '" data-aaspf="regel-ipa" placeholder="foːmalˈhuːt"></div>';
        echo '<div><label for="aaspf-alias-' . esc_attr((string) $segmentId) . '">' . esc_html__('Respelling (fallback)', 'sonoquill') . '</label>';
        echo '<input type="text" id="aaspf-alias-' . esc_attr((string) $segmentId) . '" data-aaspf="regel-alias" placeholder="Fomal-hut"></div>';
        echo '<div><button type="button" class="aaspf-knopf aaspf-knopf-haupt" data-aaspf="regel-senden">' . esc_html__('Create rule', 'sonoquill') . '</button></div>';
        echo '</div>';

        echo '<p class="aaspf-hinweis">' . esc_html(
            $art === 'phoneme'
                ? __('The configured model understands phonetic transcription — it will be used. The respelling is stored as a comment in case you later switch to a model without phonetic support.', 'sonoquill')
                : __('The configured model silently discards phonetic transcription — the respelling will be used. The phonetic transcription is stored as a comment so that switching to Eleven v3 means no rework.', 'sonoquill')
        ) . '</p>';
        echo '</div>';

        echo '<div class="aaspf-weg">';
        echo '<h4><span class="aaspf-weg-nr">2</span>' . esc_html__('Re-speak the sentence — applies only to this episode', 'sonoquill') . '</h4>';
        echo '<p class="description">' . esc_html__('Read the whole sentence aloud, not just the wrong word. The Voice Changer carries over the intonation of the recording; a word spoken on its own sounds noticeably out of place between two sentences.', 'sonoquill') . '</p>';
        echo '<p class="aaspf-vorlesen">' . esc_html((string) $segment['text']) . '</p>';
        echo '<div class="aaspf-aktionen">';
        echo '<button type="button" class="aaspf-knopf" data-aaspf="aufnahme"><span class="aaspf-punkt"></span><span data-aaspf="beschriftung">' . esc_html__('Start recording', 'sonoquill') . '</span></button>';
        echo '<span class="aaspf-segment-meta" data-aaspf="uhr"></span>';
        echo '<button type="button" class="aaspf-knopf aaspf-knopf-haupt" data-aaspf="uebernehmen" disabled>' . esc_html__('apply', 'sonoquill') . '</button>';
        echo '</div>';
        echo '<audio class="aaspf-vorschau-player" controls data-aaspf="vorschau" hidden></audio>';
        echo '</div>';

        echo '<p class="aaspf-meldung"></p>';
        echo '</div>';
    }

    /**
     * Auphonic and the Podlove draft.
     *
     * @param array<string,mixed> $episode
     */
    /**
     * What Auphonic is doing right now.
     *
     * While the production is running, the state is queried once when the page
     * is loaded — not in a loop. Polling for completion is ruled out, that is
     * what the callback is for; a single look when someone is watching is a
     * different thing and was the only missing way to see at all whether
     * anything is happening there.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderAuphonicStand(array $episode, string $uuid): void
    {
        echo '<code>' . esc_html($uuid) . '</code> ';
        echo '<a href="' . esc_url('https://auphonic.com/engine/status/' . rawurlencode($uuid)) . '" target="_blank" rel="noopener">'
            . esc_html__('view on Auphonic', 'sonoquill') . '</a>';

        if ((string) $episode['status'] !== EpisodeStatus::PRODUCING) {
            return;
        }

        try {
            $details = \Sonoquill\Auphonic\AuphonicClient::fromSettings()->details($uuid);
        } catch (\Throwable $e) {
            echo '<p class="aaspf-status aaspf-status-warn">' . esc_html(sprintf(
                /* translators: %s: error message */
                __('The status could not be retrieved: %s', 'sonoquill'),
                $e->getMessage()
            )) . '</p>';

            return;
        }

        $code = (int) ($details['status'] ?? -1);
        $wort = trim((string) ($details['status_string'] ?? ''));

        // 3 means done. If the episode is then still marked as "running", the
        // callback got lost or the step after it failed.
        if ($code === 3) {
            echo '<p class="aaspf-status aaspf-status-warn">' . esc_html__('Auphonic is done, but the episode is still marked as "producing". The callback is missing or fetching the result failed — "Resume run" catches up on it.', 'sonoquill') . '</p>';

            return;
        }

        if ($code === 9 || $code === 2) {
            echo '<p class="aaspf-status aaspf-status-fail">' . esc_html(sprintf(
                /* translators: %s: state reported by Auphonic */
                __('Auphonic reports an error: %s', 'sonoquill'),
                $wort !== '' ? $wort : (string) $code
            )) . '</p>';

            return;
        }

        echo '<p class="aaspf-status aaspf-status-running">' . esc_html(sprintf(
            /* translators: %s: state reported by Auphonic */
            __('Running — Auphonic reports "%s". The status is fetched again when the page is reloaded.', 'sonoquill'),
            $wort !== '' ? $wort : (string) $code
        )) . '</p>';
    }

    private static function renderProduction(array $episode): void
    {
        $id = (int) $episode['id'];
        $mix = (string) ($episode['mixed_audio_path'] ?? '');

        if ($mix === '' || !EpisodeStorage::exists($mix)) {
            return;
        }


        $uuid = (string) ($episode['auphonic_production_uuid'] ?? '');
        $postId = (int) ($episode['podlove_post_id'] ?? 0);
        $slug = (string) ($episode['podlove_slug'] ?? '');
        if ($slug === '') {
            $slug = SlugBuilder::fromTitle((string) ($episode['episode_title'] ?? ''), \Sonoquill\Settings\Options::filePrefix());
        }


        echo '<h3>' . esc_html__('Production', 'sonoquill') . '</h3>';
        echo '<table class="widefat striped"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Auphonic', 'sonoquill') . '</th><td>';
        if ($uuid === '') {
            echo esc_html__('not sent yet', 'sonoquill');
        } else {
            self::renderAuphonicStand($episode, $uuid);
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Callback URL', 'sonoquill') . '</th><td><code>'
            . esc_html(AuphonicWebhook::url()) . '</code>';
        echo '<p class="description">' . esc_html__('Auphonic calls this URL as soon as the production is finished. There is no polling.', 'sonoquill') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Podlove draft', 'sonoquill') . '</th><td>';
        if ($postId > 0 && get_post($postId) !== null) {
            $status = (string) get_post_status($postId);
            printf(
                '<a href="%s">%s</a> <span class="aaspf-segment-meta">(%s)</span>',
                esc_url((string) get_edit_post_link($postId)),
                esc_html(get_the_title($postId)),
                esc_html($status)
            );
            if ($status !== 'draft') {
                echo ' <span class="aaspf-status aaspf-status-warn">' . esc_html__('no longer a draft', 'sonoquill') . '</span>';
            }
            echo '<p class="description">' . esc_html__('The draft is never published automatically. Listen, review, then publish by hand.', 'sonoquill') . '</p>';
        } else {
            echo esc_html__('created after the Auphonic callback', 'sonoquill');
        }
        echo '</td></tr>';

        echo '</tbody></table>';
    }

    /**
     * The "Send to Auphonic" form with the Podlove slug.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderProduceForm(array $episode): void
    {
        $id = (int) $episode['id'];
        $uuid = (string) ($episode['auphonic_production_uuid'] ?? '');
        $slug = (string) ($episode['podlove_slug'] ?? '');
        if ($slug === '') {
            $slug = SlugBuilder::fromTitle((string) ($episode['episode_title'] ?? ''), \Sonoquill\Settings\Options::filePrefix());
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(EpisodeActions::ACTION_PRODUCE);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_PRODUCE) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<div class="aaspf-felder">';
        echo '<div><label for="aaspf-slug">' . esc_html__('Podlove slug', 'sonoquill') . '</label>';
        echo '<input type="text" id="aaspf-slug" name="slug" value="' . esc_attr($slug) . '" class="regular-text"></div>';
        echo '<div><button type="submit" class="aaspf-knopf aaspf-knopf-haupt">'
            . esc_html($uuid === '' ? __('Send to Auphonic', 'sonoquill') : __('send again', 'sonoquill'))
            . '</button></div>';
        echo '</div>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: file name */
            __('Determines the public file name: %s.mp3. Chapter marks and metadata are sent along; speech recognition must be switched off in the Auphonic preset.', 'sonoquill'),
            $slug
        )) . '</p>';
        echo '</form>';
    }

    /**
     * Cost display and cleanup.
     *
     * @param array<string,mixed> $episode
     */
    private static function renderCosts(array $episode): void
    {
        $id = (int) $episode['id'];
        $cents = (float) ($episode['cost_cents'] ?? 0);
        $chars = (int) ($episode['chars_billed'] ?? 0);
        $bytes = EpisodeStorage::sizeOfEpisode($id);

        if ($cents <= 0 && $chars <= 0 && $bytes <= 0) {
            return;
        }

        echo '<h3>' . esc_html__('Usage', 'sonoquill') . '</h3>';
        echo '<table class="widefat striped" style="max-width:46em"><tbody>';
        printf(
            '<tr><th scope="row">%s</th><td>%s</td></tr>',
            esc_html__('Anthropic, estimated', 'sonoquill'),
            /* translators: %s: estimated cost in US cents */
            esc_html(sprintf(__('%s US cents', 'sonoquill'), number_format_i18n($cents, 2)))
        );
        printf(
            '<tr><th scope="row">%s</th><td>%s</td></tr>',
            esc_html__('ElevenLabs quota', 'sonoquill'),
            /* translators: %s: number of billed characters */
            esc_html(sprintf(__('%s characters', 'sonoquill'), number_format_i18n($chars)))
        );
        printf(
            '<tr><th scope="row">%s</th><td>%s</td></tr>',
            esc_html__('Storage used', 'sonoquill'),
            esc_html(size_format($bytes))
        );
        echo '</tbody></table>';

        if ($bytes > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
            wp_nonce_field(EpisodeActions::ACTION_CLEANUP);
            echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_CLEANUP) . '">';
            echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
            echo '<button type="submit" class="aaspf-knopf">' . esc_html__('Remove raw segment recordings', 'sonoquill') . '</button>';
            echo '<p class="description">' . esc_html__('Removes only the individual segments. The finished episode and the transcript remain. Deliberately not automatic — when the next problem comes up, you still want to have them.', 'sonoquill') . '</p>';
            echo '</form>';
        }
    }

    /**
     * The existing pronunciation for a term, if there is one.
     */
    private static function existingRule(string $grapheme): string
    {
        $document = \Sonoquill\Voice\PronunciationDictionary::document();
        if ($document === null) {
            return '';
        }

        foreach ($document->rules() as $rule) {
            if ($rule['grapheme'] === $grapheme) {
                return (string) $rule['value'];
            }
        }

        return '';
    }

    private static function renderLog(int $id): void
    {
        $entries = EpisodeRepository::logEntries($id);
        if ($entries === []) {
            return;
        }

        echo '<h3>' . esc_html__('Run log', 'sonoquill') . '</h3>';
        echo '<table class="widefat striped"><tbody>';

        foreach (array_reverse($entries) as $entry) {
            echo '<tr>';
            echo '<td style="width:11em"><code>' . esc_html((string) ($entry['zeit'] ?? '')) . '</code></td>';
            echo '<td style="width:9em">' . esc_html((string) ($entry['schritt'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($entry['text'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function renderDangerZone(int $id): void
    {
        echo '<h3>' . esc_html__('Delete', 'sonoquill') . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-aaspf-confirm="' . esc_attr__('Delete this episode including its segments?', 'sonoquill') . '">';
        wp_nonce_field(EpisodeActions::ACTION_DELETE);
        echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_DELETE) . '">';
        echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
        echo '<p>';
        submit_button(__('Delete episode', 'sonoquill'), 'delete', 'submit', false);
        echo '</p>';
        echo '</form>';
    }

    private static function row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private static function statusBadge(string $status): string
    {
        $class = match ($status) {
            EpisodeStatus::TEXT_APPROVED  => 'aaspf-status-ok',
            EpisodeStatus::GATE_FAILED,
            EpisodeStatus::SOURCE_FAILED,
            EpisodeStatus::REDIGAT_FAILED => 'aaspf-status-fail',
            EpisodeStatus::SOURCE_RUNNING    => 'aaspf-status-running',
            EpisodeStatus::AWAITING_TEXT  => 'aaspf-status-warn',
            default                       => 'aaspf-status-pending',
        };

        return '<span class="aaspf-status ' . esc_attr($class) . '">' . esc_html(EpisodeStatus::label($status)) . '</span>';
    }
}
