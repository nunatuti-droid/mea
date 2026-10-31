<?php
declare(strict_types=1);

namespace Mea\Module\Design;

/**
 * Alle einstellbaren Designwerte mit Standardwert, erlaubtem Bereich und CSS-Variable.
 *
 * Die Standardwerte stehen nur hier im Code. In der Datenbank (design_tokens) liegen ausschließlich
 * Abweichungen davon. „Auf Standard zurücksetzen“ löscht diese Abweichungen.
 *
 * Feldtypen:
 *   color   HEX-Farbe #rrggbb
 *   px      Zahl in Pixeln (min, max, step); mit 'rem' => true als rem ausgegeben (Schriftgrößen)
 *   number  Zahl ohne Einheit (Zeilenhöhe, Faktor)
 *   em      Zahl in em (Buchstabenabstand)
 *   weight  Schriftgewicht 100–900
 *   choice  feste Auswahl; ausgegeben wird nur der hinterlegte CSS-Wert
 *   font    Schrift aus der Schriftbibliothek
 * 'fluid' => Schlüssel des Handy-Werts: die Variable skaliert zwischen Handy- und Desktopwert (clamp).
 */
final class DesignSchema
{
    public const TABS = [
        'typography' => ['label' => 'Typografie', 'icon' => 'type', 'intro' => 'Schriftfamilien, Schriftgrößen am Computer und Textgestaltung.'],
        'colors' => ['label' => 'Farben', 'icon' => 'palette', 'intro' => 'Globale Farbpalette, Zustände von Links und Buttons, Meldungsfarben.'],
        'layout' => ['label' => 'Layout', 'icon' => 'layout-dashboard', 'intro' => 'Inhaltsbreite, Abstände zwischen Sektionen und Elementen.'],
        'components' => ['label' => 'Komponenten', 'icon' => 'square', 'intro' => 'Buttons, Karten, Formulare, Rahmen, Eckradien und Schatten.'],
        'responsive' => ['label' => 'Responsive', 'icon' => 'monitor-smartphone', 'intro' => 'Werte für Smartphones. Zwischen Handy und Computer wird stufenlos umgerechnet, Tablets liegen dazwischen.'],
    ];

    public const TRANSFORMS = ['none' => 'normal', 'uppercase' => 'GROSSBUCHSTABEN', 'lowercase' => 'kleinbuchstaben', 'capitalize' => 'Jedes Wort Groß'];
    public const STYLES = ['normal' => 'normal', 'italic' => 'kursiv'];

    /** Schatten-Stufen. Die Farbe kommt aus --color-shadow. */
    public const SHADOWS = [
        'none' => ['label' => 'kein Schatten', 'css' => 'none'],
        'subtle' => ['label' => 'kaum sichtbar', 'css' => '0 1px 2px color-mix(in srgb, var(--color-shadow) 6%, transparent), 0 1px 3px color-mix(in srgb, var(--color-shadow) 5%, transparent)'],
        'soft' => ['label' => 'weich', 'css' => '0 1px 2px color-mix(in srgb, var(--color-shadow) 6%, transparent), 0 10px 28px -14px color-mix(in srgb, var(--color-shadow) 22%, transparent)'],
        'medium' => ['label' => 'mittel', 'css' => '0 2px 4px color-mix(in srgb, var(--color-shadow) 8%, transparent), 0 14px 32px -14px color-mix(in srgb, var(--color-shadow) 30%, transparent)'],
        'strong' => ['label' => 'kräftig', 'css' => '0 18px 40px -18px color-mix(in srgb, var(--color-shadow) 35%, transparent)'],
    ];

    /** @var list<array<string,mixed>>|null */
    private static ?array $fields = null;

    /** @return list<array<string,mixed>> */
    public static function fields(): array
    {
        if (self::$fields !== null) {
            return self::$fields;
        }
        $weights = [];
        foreach (range(100, 900, 100) as $w) {
            $weights[(string) $w] = (string) $w;
        }
        $shadow = array_map(fn($s) => $s['label'], self::SHADOWS);
        $f = [];
        $add = function (string $tab, string $group, string $key, string $type, mixed $default, string $label, array $o = []) use (&$f): void {
            $f[] = ['key' => $key, 'tab' => $tab, 'group' => $group, 'type' => $type, 'default' => $default, 'label' => $label] + $o;
        };
        $size = fn(string $var, int|float $min, int|float $max, ?string $fluid = null) => ['var' => $var, 'min' => $min, 'max' => $max, 'step' => 0.5, 'rem' => true, 'unit' => 'px'] + ($fluid ? ['fluid' => $fluid] : []);

        // ---------- Typografie ----------
        $t = 'typography';
        $add($t, 'Schriftfamilien', 'font_heading', 'font', 'plus-jakarta', 'Überschriften', ['var' => '--font-family-heading']);
        $add($t, 'Schriftfamilien', 'font_body', 'font', 'plus-jakarta', 'Fließtext', ['var' => '--font-family-body']);
        $add($t, 'Schriftfamilien', 'font_ui', 'font', 'plus-jakarta', 'Buttons, Navigation und Formulare', ['var' => '--font-family-ui']);

        $g = 'Schriftgrößen am Computer';
        $add($t, $g, 'size_display', 'px', 60, 'Große Überschrift im Startbereich', $size('--font-size-display', 28, 96, 'size_display_mobile'));
        foreach ([1 => [42, 24, 80], 2 => [34, 20, 64], 3 => [22, 16, 40], 4 => [18, 14, 32], 5 => [17, 13, 28], 6 => [15, 12, 24]] as $n => [$d, $min, $max]) {
            $add($t, $g, "size_h$n", 'px', $d, "Überschrift H$n", $size("--font-size-h$n", $min, $max, "size_h{$n}_mobile"));
        }
        $add($t, $g, 'size_lead', 'px', 21, 'Einleitungstext', $size('--font-size-lead', 14, 32, 'size_lead_mobile'));
        $add($t, $g, 'size_body', 'px', 17, 'Fließtext', $size('--font-size-body', 12, 24, 'size_body_mobile') + ['help' => 'Grundgröße der Website. 16 px entsprechen der Standardgröße des Browsers.']);
        $add($t, $g, 'size_small', 'px', 15, 'Kleiner Text (Metadaten, Labels)', $size('--font-size-small', 11, 20));
        $add($t, $g, 'size_caption', 'px', 13, 'Bildunterschriften, Badges, Hinweise', $size('--font-size-caption', 10, 18));
        $add($t, $g, 'size_nav', 'px', 15, 'Navigation', $size('--font-size-nav', 11, 22));
        $add($t, $g, 'size_button', 'px', 17, 'Buttons', $size('--font-size-button', 12, 24));
        $add($t, $g, 'size_input', 'px', 17, 'Formularfelder', $size('--font-size-input', 14, 24) + ['help' => 'Unter 16 px vergrößern manche Handys das Feld beim Antippen.']);
        $add($t, $g, 'size_price', 'px', 24, 'Preise und Kennzahlen', $size('--font-size-price', 14, 48));

        $g = 'Schriftgewicht';
        $add($t, $g, 'weight_body', 'weight', '400', 'Fließtext', ['var' => '--font-weight-body', 'options' => $weights]);
        $add($t, $g, 'weight_heading', 'weight', '700', 'Überschriften', ['var' => '--font-weight-heading', 'options' => $weights]);
        $add($t, $g, 'weight_display', 'weight', '800', 'Große Überschrift, Preise', ['var' => '--font-weight-display', 'options' => $weights]);
        $add($t, $g, 'weight_ui', 'weight', '600', 'Buttons, Navigation, Labels', ['var' => '--font-weight-ui', 'options' => $weights]);
        $add($t, $g, 'weight_strong', 'weight', '700', 'Hervorhebungen, Badges', ['var' => '--font-weight-strong', 'options' => $weights]);

        $g = 'Zeilenhöhe';
        $add($t, $g, 'lh_body', 'number', 1.65, 'Fließtext', ['var' => '--line-height-body', 'min' => 1, 'max' => 2.4, 'step' => 0.01]);
        $add($t, $g, 'lh_compact', 'number', 1.5, 'Kleine Texte in Karten und Listen', ['var' => '--line-height-compact', 'min' => 1, 'max' => 2.2, 'step' => 0.01]);
        $add($t, $g, 'lh_heading', 'number', 1.15, 'Überschriften H1–H2', ['var' => '--line-height-heading', 'min' => 0.9, 'max' => 1.8, 'step' => 0.01]);
        $add($t, $g, 'lh_heading_sm', 'number', 1.3, 'Überschriften H3–H6', ['var' => '--line-height-heading-small', 'min' => 0.9, 'max' => 1.8, 'step' => 0.01]);
        $add($t, $g, 'lh_display', 'number', 1.06, 'Große Überschrift im Startbereich', ['var' => '--line-height-display', 'min' => 0.85, 'max' => 1.6, 'step' => 0.01]);
        $add($t, $g, 'lh_ui', 'number', 1.2, 'Buttons und Navigation', ['var' => '--line-height-ui', 'min' => 0.9, 'max' => 1.8, 'step' => 0.01]);

        $g = 'Buchstabenabstand';
        $ls = fn(string $var) => ['var' => $var, 'min' => -0.1, 'max' => 0.3, 'step' => 0.005, 'unit' => 'em'];
        $add($t, $g, 'ls_body', 'em', 0, 'Fließtext', $ls('--letter-spacing-body'));
        $add($t, $g, 'ls_heading', 'em', -0.015, 'Überschriften', $ls('--letter-spacing-heading'));
        $add($t, $g, 'ls_display', 'em', -0.025, 'Große Überschrift im Startbereich', $ls('--letter-spacing-display'));
        $add($t, $g, 'ls_ui', 'em', 0, 'Buttons und Navigation', $ls('--letter-spacing-ui'));
        $add($t, $g, 'ls_caps', 'em', 0.08, 'Kleine Zeilen in Großbuchstaben', $ls('--letter-spacing-caps') + ['help' => 'z. B. die kleine Zeile über Abschnittsüberschriften.']);

        $g = 'Texttransformation und Schriftstil';
        $add($t, $g, 'tt_heading', 'choice', 'none', 'Überschriften', ['var' => '--text-transform-heading', 'options' => self::TRANSFORMS]);
        $add($t, $g, 'tt_button', 'choice', 'none', 'Buttons', ['var' => '--text-transform-button', 'options' => self::TRANSFORMS]);
        $add($t, $g, 'tt_nav', 'choice', 'none', 'Navigation', ['var' => '--text-transform-nav', 'options' => self::TRANSFORMS]);
        $add($t, $g, 'tt_eyebrow', 'choice', 'uppercase', 'Kleine Zeile über Überschriften', ['var' => '--text-transform-eyebrow', 'options' => self::TRANSFORMS]);
        $add($t, $g, 'fs_heading', 'choice', 'normal', 'Schriftstil Überschriften', ['var' => '--font-style-heading', 'options' => self::STYLES]);
        $add($t, $g, 'fs_body', 'choice', 'normal', 'Schriftstil Fließtext', ['var' => '--font-style-body', 'options' => self::STYLES]);

        // ---------- Farben ----------
        $c = 'colors';
        $col = fn(string $var, string $help = '') => ['var' => $var] + ($help !== '' ? ['help' => $help] : []);
        $g = 'Markenfarben';
        $add($c, $g, 'color_primary', 'color', '#3f8d7b', 'Primärfarbe', $col('--color-primary', 'Flächen, Symbole, Rauten, Rahmen. Nicht für weiße Schrift.'));
        $add($c, $g, 'color_primary_strong', 'color', '#317566', 'Primärfarbe dunkel', $col('--color-primary-strong', 'Symbole und kleine Schrift in Primärfarbe auf hellem Grund.'));
        $add($c, $g, 'color_secondary', 'color', '#0e3042', 'Sekundärfarbe', $col('--color-secondary', 'Dunkle Flächen: Kopfzeile oben, Startbereich, Fußbereich, Infokästen.'));
        $add($c, $g, 'color_accent', 'color', '#e8a33d', 'Akzentfarbe', $col('--color-accent', 'Sparsam: Sterne, „wenige Plätze“, Hinweisleisten.'));
        $g = 'Hintergründe';
        $add($c, $g, 'color_background', 'color', '#f6f9f8', 'Hintergrund der Website', $col('--color-background'));
        $add($c, $g, 'color_surface', 'color', '#ffffff', 'Inhaltsbereiche und Karten', $col('--color-surface'));
        $add($c, $g, 'color_surface_alt', 'color', '#eaf1ef', 'Abwechselnde Abschnitte', $col('--color-surface-alt', 'Getönte Abschnitte, Seitenkopf der Unterseiten, Tabellenkopf.'));
        $g = 'Text';
        $add($c, $g, 'color_text', 'color', '#22394a', 'Standard-Textfarbe', $col('--color-text'));
        $add($c, $g, 'color_text_muted', 'color', '#4a5b66', 'Nebentext', $col('--color-text-muted', 'Metadaten, Einleitungen, Hinweise.'));
        $add($c, $g, 'color_heading', 'color', '#0e3042', 'Überschriften', $col('--color-heading', 'Auch für hervorgehobenen Text, Preise und Labels.'));
        $add($c, $g, 'color_link', 'color', '#317566', 'Links', $col('--color-link'));
        $add($c, $g, 'color_link_hover', 'color', '#285f53', 'Links beim Überfahren', $col('--color-link-hover'));
        $g = 'Text auf dunklem Grund';
        $add($c, $g, 'color_on_dark', 'color', '#ffffff', 'Schrift auf dunklem Grund', $col('--color-on-dark'));
        $add($c, $g, 'color_on_dark_muted', 'color', '#c5d3d9', 'Nebentext auf dunklem Grund', $col('--color-on-dark-muted'));
        $add($c, $g, 'color_on_dark_accent', 'color', '#8fd3c1', 'Hervorhebung auf dunklem Grund', $col('--color-on-dark-accent'));
        $g = 'Buttons';
        $add($c, $g, 'color_button', 'color', '#317566', 'Button-Hintergrund', $col('--color-button'));
        $add($c, $g, 'color_button_text', 'color', '#ffffff', 'Button-Schrift', $col('--color-button-text'));
        $add($c, $g, 'color_button_hover', 'color', '#285f53', 'Button beim Überfahren', $col('--color-button-hover'));
        $add($c, $g, 'color_button_active', 'color', '#1f4c42', 'Button beim Klicken', $col('--color-button-active'));
        $add($c, $g, 'color_focus', 'color', '#e8a33d', 'Fokusrahmen (Tastatur)', $col('--color-focus', 'Rahmen um das gerade mit der Tastatur gewählte Element.'));
        $g = 'Rahmen und Linien';
        $add($c, $g, 'color_border', 'color', '#d3e0dc', 'Rahmen und Trennlinien', $col('--color-border'));
        $add($c, $g, 'color_border_strong', 'color', '#b9cdc7', 'Kräftige Rahmen', $col('--color-border-strong', 'Hover von Karten und Feldern, Logo-Kacheln.'));
        $add($c, $g, 'color_input_border', 'color', '#c6d5d1', 'Rahmen von Formularfeldern', $col('--color-input-border'));
        $add($c, $g, 'color_input_bg', 'color', '#fbfdfc', 'Hintergrund von Formularfeldern', $col('--color-input-background'));
        $add($c, $g, 'color_shadow', 'color', '#0e3042', 'Schattenfarbe', $col('--color-shadow', 'Wird stark aufgehellt; meist passt die Sekundärfarbe.'));
        $g = 'Meldungen';
        $add($c, $g, 'color_success', 'color', '#276b44', 'Erfolg', $col('--color-success'));
        $add($c, $g, 'color_warning', 'color', '#9a5b00', 'Warnung', $col('--color-warning'));
        $add($c, $g, 'color_error', 'color', '#b42318', 'Fehler', $col('--color-error'));

        // ---------- Layout ----------
        $l = 'layout';
        $px = fn(string $var, int|float $min, int|float $max, float $step = 1, ?string $fluid = null) => ['var' => $var, 'min' => $min, 'max' => $max, 'step' => $step, 'unit' => 'px'] + ($fluid ? ['fluid' => $fluid] : []);
        $g = 'Breiten';
        $add($l, $g, 'content_max_width', 'px', 1200, 'Maximale Inhaltsbreite', $px('--content-max-width', 840, 1680, 10));
        $add($l, $g, 'text_measure', 'number', 68, 'Maximale Zeilenlänge im Fließtext (Zeichen)', ['var' => '--text-measure', 'min' => 40, 'max' => 110, 'step' => 1, 'unit' => 'ch', 'suffix' => 'ch']);
        $add($l, $g, 'gutter', 'px', 24, 'Seitenrand am Computer', $px('--page-gutter', 8, 64, 1, 'gutter_mobile'));
        $g = 'Abstände';
        $add($l, $g, 'section_spacing', 'px', 62, 'Abstand zwischen Sektionen am Computer', $px('--section-spacing', 16, 200, 1, 'section_spacing_mobile'));
        $add($l, $g, 'space_scale', 'number', 1, 'Abstandsfaktor (Innen- und Außenabstände)', ['var' => '--space-scale', 'min' => 0.5, 'max' => 2, 'step' => 0.05, 'help' => '1 = Standard. 0,9 macht die Abstände in und zwischen Elementen 10 % enger, 1,1 um 10 % weiter. Sektionsabstand und Seitenrand stellen Sie getrennt ein.']);
        $add($l, $g, 'space_heading', 'px', 16, 'Abstand Überschrift → Text', $px('--space-heading', 0, 64) + ['scaled' => true]);
        $add($l, $g, 'space_paragraph', 'px', 16, 'Abstand zwischen Absätzen', $px('--space-paragraph', 0, 64) + ['scaled' => true]);
        $add($l, $g, 'space_element', 'px', 24, 'Abstand zwischen Elementen (Karten, Spalten)', $px('--space-element', 4, 80) + ['scaled' => true]);
        $add($l, $g, 'space_section_head', 'px', 32, 'Abstand Abschnittsüberschrift → Inhalt', $px('--space-section-head', 0, 120) + ['scaled' => true]);

        // ---------- Komponenten ----------
        $k = 'components';
        $g = 'Buttons';
        $add($k, $g, 'button_height', 'px', 48, 'Mindesthöhe', $px('--button-height', 32, 72) + ['help' => 'Für Touch-Bedienung mindestens 44 px.']);
        $add($k, $g, 'button_padding_x', 'px', 24, 'Innenabstand links/rechts', $px('--button-padding-x', 8, 64));
        $add($k, $g, 'radius_button', 'px', 999, 'Eckradius', $px('--radius-button', 0, 999) + ['help' => '999 = vollständig rund (Pille), 0 = eckig.']);
        $add($k, $g, 'border_button', 'px', 2, 'Rahmenstärke', $px('--border-width-button', 0, 6, 0.5));
        $g = 'Karten und Inhaltsboxen';
        $add($k, $g, 'card_padding', 'px', 24, 'Innenabstand', $px('--card-padding', 8, 64) + ['scaled' => true]);
        $add($k, $g, 'radius_card', 'px', 14, 'Eckradius', $px('--radius-card', 0, 48));
        $add($k, $g, 'border_width', 'px', 1, 'Rahmenstärke (Karten, Linien)', $px('--border-width', 0, 6, 0.5));
        $add($k, $g, 'shadow_card', 'choice', 'soft', 'Schatten', ['var' => '--shadow-card', 'options' => $shadow, 'css' => 'shadow']);
        $add($k, $g, 'shadow_raised', 'choice', 'strong', 'Schatten beim Überfahren, Menüs, Dialoge', ['var' => '--shadow-raised', 'options' => $shadow, 'css' => 'shadow']);
        $add($k, $g, 'shadow_small', 'choice', 'subtle', 'Schatten kleiner Elemente (Bilder, Badges)', ['var' => '--shadow-small', 'options' => $shadow, 'css' => 'shadow']);
        $add($k, $g, 'card_hover_lift', 'px', 3, 'Anheben beim Überfahren', $px('--card-hover-lift', 0, 12));
        $g = 'Formulare';
        $add($k, $g, 'input_height', 'px', 52, 'Höhe der Eingabefelder', $px('--input-height', 36, 72));
        $add($k, $g, 'radius_input', 'px', 12, 'Eckradius der Eingabefelder', $px('--radius-input', 0, 32));
        $add($k, $g, 'border_input', 'px', 1.5, 'Rahmenstärke der Eingabefelder', $px('--border-width-input', 0.5, 4, 0.5));
        $add($k, $g, 'focus_width', 'px', 3, 'Stärke des Fokusrahmens', $px('--focus-ring-width', 2, 6, 0.5) + ['help' => 'Mindestens 2 px, damit Tastaturnutzer den Fokus sehen.']);
        $g = 'Bilder und kleine Elemente';
        $add($k, $g, 'radius_image', 'px', 24, 'Eckradius großer Bilder und Flächen', $px('--radius-image', 0, 64));
        $add($k, $g, 'radius_corner', 'px', 6, 'Spitze Ecke unten links (Markenzeichen)', $px('--radius-corner', 0, 64) + ['help' => 'Gleicher Wert wie oben = keine spitze Ecke.']);
        $add($k, $g, 'radius_small', 'px', 8, 'Eckradius kleiner Elemente (Menü, Badges)', $px('--radius-small', 0, 24));

        // ---------- Responsive ----------
        $r = 'responsive';
        $g = 'Umrechnungsbereich';
        $add($r, $g, 'viewport_min', 'px', 375, 'Bildschirmbreite Handy', $px('', 320, 640, 1) + ['help' => 'Bis zu dieser Breite gelten die Handy-Werte.']);
        $add($r, $g, 'viewport_max', 'px', 1280, 'Bildschirmbreite Computer', $px('', 960, 1920, 1) + ['help' => 'Ab dieser Breite gelten die Computer-Werte. Dazwischen (Tablet) wird stufenlos umgerechnet.']);
        $g = 'Schriftgrößen auf dem Handy';
        foreach ([['size_display_mobile', 40, 'Große Überschrift im Startbereich', 24, 72], ['size_h1_mobile', 32, 'Überschrift H1', 20, 64], ['size_h2_mobile', 26, 'Überschrift H2', 18, 48],
            ['size_h3_mobile', 20, 'Überschrift H3', 14, 32], ['size_h4_mobile', 18, 'Überschrift H4', 13, 28], ['size_h5_mobile', 16, 'Überschrift H5', 12, 24], ['size_h6_mobile', 15, 'Überschrift H6', 12, 22],
            ['size_lead_mobile', 19, 'Einleitungstext', 14, 28], ['size_body_mobile', 17, 'Fließtext', 14, 22]] as [$key, $d, $label, $min, $max]) {
            $add($r, $g, $key, 'px', $d, $label, ['var' => null, 'min' => $min, 'max' => $max, 'step' => 0.5, 'unit' => 'px']);
        }
        $add($r, $g, 'size_nav_mobile', 'px', 17, 'Menü auf Handy und Tablet', $size('--font-size-nav-mobile', 13, 24));
        $g = 'Abstände auf dem Handy';
        $add($r, $g, 'section_spacing_mobile', 'px', 44, 'Abstand zwischen Sektionen', ['var' => null, 'min' => 16, 'max' => 160, 'step' => 1, 'unit' => 'px']);
        $add($r, $g, 'gutter_mobile', 'px', 16, 'Seitenrand', ['var' => null, 'min' => 8, 'max' => 40, 'step' => 1, 'unit' => 'px']);

        return self::$fields = $f;
    }

    /** @return array<string,array<string,mixed>> */
    public static function byKey(): array
    {
        $out = [];
        foreach (self::fields() as $f) {
            $out[$f['key']] = $f;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::fields() as $f) {
            $out[$f['key']] = $f['default'];
        }
        return $out;
    }

    /** Fingerabdruck der Standardwerte und des CSS-Aufbaus; ändert er sich (Update), wird die CSS-Datei neu erzeugt. */
    public static function fingerprint(): string
    {
        static $fp = null;
        return $fp ??= substr(hash('sha256', DesignCss::GENERATOR . json_encode(self::fields())), 0, 12);
    }
}
