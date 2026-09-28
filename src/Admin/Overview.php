<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters of admin screens.

use PodcastForge\Db\EpisodeRepository;
use PodcastForge\Db\EpisodeStatus;
use PodcastForge\Health\Registry;
use PodcastForge\Settings\Options;

/**
 * The overview: key figures, episodes in progress, add-on panels, all
 * episodes. Built from WordPress's own components, with key figure cards at
 * the top and plain tables below.
 */
final class Overview
{
    /** Episodes that should not appear in the overview (test and comparison runs). */
    public const HIDDEN_OPTION = 'aaspf_ausgeblendet';

    public static function render(): void
    {
        $showHidden = isset($_GET['alle']);
        $hidden = self::hiddenIds();
        $episodes = EpisodeRepository::recent(60);

        echo '<h1 class="wp-heading-inline">' . esc_html__('Podcast Forge — Overview', 'podcast-forge') . '</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(Urls::episodes(['neu' => 1])) . '">'
            . esc_html__('New episode', 'podcast-forge') . '</a>';
        echo '<hr class="wp-header-end">';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: name of the podcast */
            __('%s — two approvals stay with you: text and audio.', 'podcast-forge'),
            Options::podcastName()
        )) . '</p>';

        EpisodesPage::renderStuckNotice();

        $active = array_values(array_filter($episodes, static fn (array $e): bool => (string) $e['status'] !== EpisodeStatus::DONE
            && !in_array((int) $e['id'], $hidden, true)));

        self::renderCards($active);

        foreach ($active as $episode) {
            self::renderActive($episode);
        }

        // Add-ons can show their own panels here (action `podcast_forge_overview_panels`,
        // one <section class="aaspf-panel"> per panel).
        ob_start();
        do_action('podcast_forge_overview_panels');
        $panels = trim((string) ob_get_clean());
        if ($panels !== '') {
            echo '<div class="aaspf-zweispaltig">' . wp_kses($panels, Html::allowed()) . '</div>';
        }

        self::renderTable($episodes, $hidden, $showHidden);
    }

    /**
     * @param list<array<string,mixed>> $active
     */
    private static function renderCards(array $active): void
    {
        echo '<div class="aaspf-kennzahlen">';

        $first = $active[0] ?? null;
        if ($first !== null) {
            $current = Workflow::current(Workflow::stations($first));
            self::card(
                __('In progress', 'podcast-forge'),
                self::shortTitle($first),
                $current !== null ? sprintf('%s · %s', $current['titel'], $current['wort']) : EpisodeStatus::label((string) $first['status'])
            );
        } else {
            self::card(__('In progress', 'podcast-forge'), __('nothing', 'podcast-forge'), __('no open episode', 'podcast-forge'));
        }

        /**
         * Filter: additional key figures for the overview, each as [title, value, subline, tone ''|'warn'].
         */
        foreach ((array) apply_filters('podcast_forge_overview_cards', []) as $extra) {
            if (is_array($extra) && count($extra) >= 3) {
                self::card((string) $extra[0], (string) $extra[1], (string) $extra[2], (string) ($extra[3] ?? ''));
            }
        }

        $quota = self::elevenLabsQuota();
        if ($quota !== null) {
            self::card(
                __('ElevenLabs quota', 'podcast-forge'),
                number_format_i18n($quota['genutzt']),
                /* translators: %s: character limit of the ElevenLabs plan */
                sprintf(__('of %s characters in this billing month', 'podcast-forge'), number_format_i18n($quota['grenze'])),
                $quota['grenze'] > 0 && $quota['genutzt'] / $quota['grenze'] > 0.85 ? 'warn' : ''
            );
        } else {
            self::card(__('ElevenLabs quota', 'podcast-forge'), '—', __('unavailable', 'podcast-forge'));
        }

        $results = Registry::lastResults();
        $total = count(Registry::all());
        if ($results === []) {
            self::card(__('Services', 'podcast-forge'), '—', __('not checked yet · check in the settings', 'podcast-forge'));
        } else {
            $ok = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'ok'));
            $problem = null;
            foreach ($results as $r) {
                if ($r['status'] !== 'ok') {
                    $problem = $r['message'];
                    break;
                }
            }
            $oldest = min(array_map(static fn (array $r): int => (int) $r['zeit'], $results));
            self::card(
                __('Services', 'podcast-forge'),
                /* translators: 1: number of services working fine, 2: total number of services */
                sprintf(__('%1$d of %2$d', 'podcast-forge'), $ok, $total),
                /* translators: %s: time since the last check, e.g. "5 mins" */
                $problem ?? sprintf(__('all fine · checked %s ago', 'podcast-forge'), human_time_diff($oldest)),
                $ok === $total && count($results) === $total ? 'ok' : 'warn'
            );
        }

        echo '</div>';
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function renderActive(array $episode): void
    {
        $id = (int) $episode['id'];
        $stations = Workflow::stations($episode);
        $current = Workflow::current($stations);

        echo '<section class="aaspf-panel aaspf-aktiv">';
        echo '<div class="aaspf-panel-kopf">';
        echo '<h2>' . esc_html(self::title($episode)) . '</h2>';
        echo wp_kses(EpisodesPage::statusPill($episode), Html::allowed());
        echo '<a class="button button-primary" href="' . esc_url(Urls::episode($id)) . '">' . esc_html__('Open', 'podcast-forge') . '</a>';
        echo '</div>';

        EpisodesPage::renderSteps($stations, true);

        if ($current !== null) {
            echo '<p class="description">' . esc_html(EpisodesPage::nextHint($episode, $current['key'])) . '</p>';
        }
        echo '</section>';
    }

    /**
     * @param list<array<string,mixed>> $episodes
     * @param list<int>                 $hidden
     */
    private static function renderTable(array $episodes, array $hidden, bool $showHidden): void
    {
        echo '<h2 class="aaspf-abschnitt">' . esc_html__('All episodes', 'podcast-forge') . '</h2>';

        if ($episodes === []) {
            echo '<p>' . esc_html__('No episode created yet.', 'podcast-forge') . '</p>';

            return;
        }

        echo '<table class="widefat striped aaspf-folgen"><thead><tr>';
        foreach ([
            [__('No.', 'podcast-forge'), ''], [__('Title', 'podcast-forge'), ''], [__('Source', 'podcast-forge'), 'aaspf-schmal-weg'],
            [__('Status', 'podcast-forge'), ''], [__('Length', 'podcast-forge'), 'aaspf-rechts aaspf-schmal-weg'],
            [__('Cost', 'podcast-forge'), 'aaspf-rechts aaspf-schmal-weg'], [__('Created', 'podcast-forge'), 'aaspf-schmal-weg'], ['', ''],
        ] as [$head, $class]) {
            echo '<th scope="col" class="' . esc_attr($class) . '">' . esc_html($head) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $hiddenCount = 0;
        foreach ($episodes as $episode) {
            $id = (int) $episode['id'];
            $isHidden = in_array($id, $hidden, true);
            if ($isHidden) {
                $hiddenCount++;
                if (!$showHidden) {
                    continue;
                }
            }

            echo '<tr' . ($isHidden ? ' class="aaspf-ausgeblendet"' : '') . '>';
            echo '<td>' . esc_html((string) $id) . '</td>';
            echo '<td><a href="' . esc_url(Urls::episode($id)) . '"><strong>' . esc_html(self::title($episode)) . '</strong></a></td>';
            echo '<td class="aaspf-schmal-weg">' . esc_html(\PodcastForge\Source\Sources::forEpisode($episode)->label()) . '</td>';
            echo '<td>' . wp_kses(EpisodesPage::statusPill($episode), Html::allowed()) . '</td>';
            echo '<td class="aaspf-rechts aaspf-schmal-weg">' . esc_html(EpisodesPage::lengthLabel($episode)) . '</td>';
            echo '<td class="aaspf-rechts aaspf-schmal-weg">' . esc_html(number_format_i18n((float) $episode['cost_cents'] / 100, 2) . ' $') . '</td>';
            echo '<td class="aaspf-schmal-weg">' . esc_html(mysql2date('d.m.Y', (string) $episode['created_at'])) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(EpisodeActions::ACTION_HIDE);
            echo '<input type="hidden" name="action" value="' . esc_attr(EpisodeActions::ACTION_HIDE) . '">';
            echo '<input type="hidden" name="episode" value="' . esc_attr((string) $id) . '">';
            echo '<button type="submit" class="button-link">' . esc_html($isHidden ? __('show', 'podcast-forge') : __('hide', 'podcast-forge')) . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($hiddenCount > 0) {
            echo '<p class="description">';
            if ($showHidden) {
                echo '<a href="' . esc_url(Urls::episodes()) . '">' . esc_html__('Hide hidden episodes again', 'podcast-forge') . '</a>';
            } else {
                echo esc_html(sprintf(
                    /* translators: %d: number of episodes */
                    _n('%d episode is hidden', '%d episodes are hidden', $hiddenCount, 'podcast-forge'),
                    $hiddenCount
                )) . ' · <a href="' . esc_url(Urls::episodes(['alle' => 1])) . '">' . esc_html__('show', 'podcast-forge') . '</a>';
            }
            echo '</p>';
        }
    }

    /**
     * @return list<int>
     */
    public static function hiddenIds(): array
    {
        $ids = get_option(self::HIDDEN_OPTION, []);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public static function toggleHidden(int $id): bool
    {
        $ids = self::hiddenIds();
        $hidden = !in_array($id, $ids, true);
        $ids = $hidden ? array_merge($ids, [$id]) : array_values(array_diff($ids, [$id]));
        update_option(self::HIDDEN_OPTION, array_values(array_unique($ids)), false);

        return $hidden;
    }

    /**
     * @param array<string,mixed> $episode
     */
    public static function title(array $episode): string
    {
        foreach (['episode_title', 'source_filename'] as $field) {
            $value = trim((string) ($episode[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        /* translators: %d: episode ID */
        return sprintf(__('Episode %d', 'podcast-forge'), (int) $episode['id']);
    }

    /**
     * Short name of the source (e.g. "Sky preview October 2026"), otherwise the title.
     *
     * @param array<string,mixed> $episode
     */
    private static function shortTitle(array $episode): string
    {
        $fromSource = \PodcastForge\Source\Sources::forEpisode($episode)->shortName($episode);
        if ($fromSource !== '') {
            return mb_strimwidth($fromSource, 0, 32, '…');
        }

        return mb_strimwidth(self::title($episode), 0, 28, '…');
    }

    private static function card(string $label, string $value, string $sub, string $tone = ''): void
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
     * Usage at ElevenLabs, cached for one hour.
     *
     * @return array{genutzt:int,grenze:int}|null
     */
    private static function elevenLabsQuota(): ?array
    {
        $cached = get_transient('aaspf_elevenlabs_kontingent');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (\Throwable) {
            return null;
        }
        if ($key === '') {
            return null;
        }

        $response = wp_remote_get('https://api.elevenlabs.io/v1/user/subscription', [
            'timeout' => 8,
            'headers' => ['xi-api-key' => $key],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !isset($data['character_count'], $data['character_limit'])) {
            return null;
        }

        $quota = ['genutzt' => (int) $data['character_count'], 'grenze' => (int) $data['character_limit']];
        set_transient('aaspf_elevenlabs_kontingent', $quota, HOUR_IN_SECONDS);

        return $quota;
    }
}
