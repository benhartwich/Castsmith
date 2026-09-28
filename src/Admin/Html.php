<?php
declare(strict_types=1);

namespace PodcastForge\Admin;

/**
 * The markup of the plugin's own admin screens, for wp_kses().
 *
 * Parts of a screen are built by helpers or by add-ons and put together
 * later (panels, the audio section, settings rows). They are escaped where
 * they are built and filtered once more with this allowlist where they are
 * output.
 */
final class Html
{
    /**
     * @return array<string,array<string,bool>>
     */
    public static function allowed(): array
    {
        $common = [
            'class'  => true,
            'id'     => true,
            'style'  => true,
            'title'  => true,
            'role'   => true,
            'hidden' => true,
            'data-*' => true,
            // wp_kses() knows data-* as a wildcard, but not aria-*.
            'aria-controls'    => true,
            'aria-current'     => true,
            'aria-describedby' => true,
            'aria-expanded'    => true,
            'aria-hidden'      => true,
            'aria-label'       => true,
            'aria-labelledby'  => true,
            'aria-live'        => true,
            'aria-selected'    => true,
        ];

        $field = $common + [
            'name'     => true,
            'value'    => true,
            'form'     => true,
            'disabled' => true,
            'required' => true,
        ];

        return [
            'a'        => $common + ['href' => true, 'rel' => true, 'target' => true, 'download' => true],
            'aside'    => $common,
            'audio'    => $common + ['src' => true, 'controls' => true, 'preload' => true],
            'b'        => $common,
            'br'       => $common,
            'button'   => $field + ['type' => true],
            'code'     => $common,
            'details'  => $common + ['open' => true],
            'div'      => $common,
            'em'       => $common,
            'form'     => $common + ['action' => true, 'method' => true, 'enctype' => true],
            'h1'       => $common,
            'h2'       => $common,
            'h3'       => $common,
            'h4'       => $common,
            'hr'       => $common,
            'i'        => $common,
            'input'    => $field + [
                'type'         => true,
                'checked'      => true,
                'accept'       => true,
                'autocomplete' => true,
                'placeholder'  => true,
                'min'          => true,
                'max'          => true,
                'step'         => true,
                'size'         => true,
                'readonly'     => true,
            ],
            'label'    => $common + ['for' => true],
            'li'       => $common,
            'nav'      => $common,
            'ol'       => $common,
            'optgroup' => $common + ['label' => true, 'disabled' => true],
            'option'   => $common + ['value' => true, 'selected' => true, 'disabled' => true],
            'p'        => $common,
            'pre'      => $common,
            'section'  => $common,
            'select'   => $field + ['multiple' => true],
            'small'    => $common,
            'span'     => $common,
            'strong'   => $common,
            'summary'  => $common,
            'table'    => $common,
            'tbody'    => $common,
            'td'       => $common + ['colspan' => true, 'rowspan' => true],
            'textarea' => $field + ['rows' => true, 'cols' => true, 'placeholder' => true, 'spellcheck' => true, 'readonly' => true],
            'th'       => $common + ['scope' => true, 'colspan' => true, 'rowspan' => true],
            'thead'    => $common,
            'time'     => $common + ['datetime' => true],
            'tr'       => $common,
            'ul'       => $common,
        ];
    }
}
