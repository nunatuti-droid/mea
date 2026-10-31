<?php
declare(strict_types=1);

namespace Mea\Module\Design;

/**
 * Erzeugt aus geprüften Werten die CSS-Variablen (:root) und die @font-face-Regeln.
 * Hier entstehen alle Werte neu aus Zahlen, HEX-Farben und festen Listen; Benutzertext gelangt nie ins CSS.
 */
final class DesignCss
{
    /** Bei Änderungen am Aufbau erhöhen, damit vorhandene CSS-Dateien neu erzeugt werden. */
    public const GENERATOR = 'design-css-3';

    /** Abstandsskala in px (wird mit --space-scale multipliziert) */
    private const SPACE = [1 => 4, 2 => 8, 3 => 12, 4 => 16, 5 => 24, 6 => 32, 7 => 48, 8 => 64, 9 => 96];

    /** Abgeleitete Werte: Beziehungen zwischen den Variablen, keine eigenen Designwerte. */
    private const DERIVED = [
        '--color-primary-soft' => 'color-mix(in srgb, var(--color-primary) 12%, var(--color-surface))',
        '--color-accent-soft' => 'color-mix(in srgb, var(--color-accent) 14%, var(--color-surface))',
        '--color-success-soft' => 'color-mix(in srgb, var(--color-success) 10%, var(--color-surface))',
        '--color-warning-soft' => 'color-mix(in srgb, var(--color-warning) 10%, var(--color-surface))',
        '--color-error-soft' => 'color-mix(in srgb, var(--color-error) 9%, var(--color-surface))',
        '--color-neutral-soft' => 'color-mix(in srgb, var(--color-text-muted) 10%, var(--color-surface))',
        '--color-overlay' => 'color-mix(in srgb, var(--color-secondary) 50%, transparent)',
        '--radius-shape' => 'var(--radius-image) var(--radius-image) var(--radius-image) var(--radius-corner)',
        '--radius-pill' => '999px',
    ];

    public function __construct(private FontLibrary $fonts) {}

    /**
     * Alle Variablen mit Wert (geprüfte Werte erwartet).
     * @param array<string,mixed> $v @return array<string,string>
     */
    public function tokens(array $v): array
    {
        $out = [];
        foreach (DesignSchema::fields() as $f) {
            if (empty($f['var'])) {
                continue;
            }
            $value = $v[$f['key']];
            $out[$f['var']] = match ($f['type']) {
                'color', 'weight' => (string) $value,
                'choice' => ($f['css'] ?? null) === 'shadow' ? DesignSchema::SHADOWS[$value]['css'] : (string) $value,
                'font' => $this->fonts->stack((string) $value),
                'em' => self::fmt((float) $value) . 'em',
                'number' => self::fmt((float) $value) . ($f['suffix'] ?? ''),
                'px' => isset($f['fluid'])
                    ? self::fluid((float) $v[$f['fluid']], (float) $value, (int) $v['viewport_min'], (int) $v['viewport_max'], !empty($f['rem']))
                    : (!empty($f['rem']) ? self::fmt((float) $value / 16) . 'rem'
                        : (!empty($f['scaled']) ? 'calc(' . self::fmt((float) $value) . 'px * var(--space-scale))' : self::fmt((float) $value) . 'px')),
            };
        }
        foreach (self::SPACE as $n => $px) {
            $out["--space-$n"] = "calc({$px}px * var(--space-scale))";
        }
        return $out + self::DERIVED;
    }

    /** @param array<string,mixed> $v */
    public function stylesheet(array $v): string
    {
        $css = "/* MEA Designsystem – erzeugt aus den Einstellungen der Verwaltung (Designsystem). Nicht von Hand bearbeiten. */\n";
        $css .= $this->fonts->fontFaceCss();
        $css .= ":root {\n";
        foreach ($this->tokens($v) as $name => $value) {
            $css .= "  $name: $value;\n";
        }
        return $css . "}\n" . self::breakpointRules((int) $v['bp_tablet'], (int) $v['bp_mobile']);
    }

    /** Spalten und Raster des Seitenbaukastens brechen an den Umbruchpunkten aus dem Designsystem um (Media Queries kennen keine Variablen). */
    public static function breakpointRules(int $tablet, int $mobile): string
    {
        return "@media (max-width: {$tablet}px) {\n"
            . "  .cols.cols--stack-tablet { grid-template-columns: minmax(0, 1fr); }\n"
            . "  .cols.cols--stack-tablet.cols--reverse > :first-child { order: 2; }\n"
            . "  .b-grid--3.b-grid--3, .b-grid--4.b-grid--4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }\n"
            . "}\n"
            . "@media (max-width: {$mobile}px) {\n"
            . "  .cols.cols--stack-mobile { grid-template-columns: minmax(0, 1fr); }\n"
            . "  .cols.cols--stack-mobile.cols--reverse > :first-child { order: 2; }\n"
            . "  .b-grid--2.b-grid--2, .b-grid--3.b-grid--3, .b-grid--4.b-grid--4 { grid-template-columns: minmax(0, 1fr); }\n"
            . "  .gallery.b-grid--3.b-grid--3, .gallery.b-grid--4.b-grid--4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }\n"
            . "}\n";
    }

    /**
     * Stufenlose Größe zwischen Handy- und Computerwert (clamp).
     * Unterhalb von $vwMin gilt $mobile, oberhalb von $vwMax gilt $desktop.
     */
    public static function fluid(float $mobile, float $desktop, int $vwMin, int $vwMax, bool $rem): string
    {
        $unit = $rem ? 'rem' : 'px';
        $conv = fn(float $px): float => $rem ? $px / 16 : $px;
        if (abs($mobile - $desktop) < 0.001 || $vwMax <= $vwMin) {
            return self::fmt($conv($desktop)) . $unit;
        }
        $slope = ($desktop - $mobile) / ($vwMax - $vwMin);
        $intercept = $mobile - $slope * $vwMin;
        $vw = self::fmt(round($slope * 100, 4)) . 'vw';
        $base = $conv($intercept);
        $middle = $base >= 0 ? self::fmt($base) . $unit . ' + ' . $vw : $vw . ' - ' . self::fmt(-$base) . $unit;
        return 'clamp(' . self::fmt($conv(min($mobile, $desktop))) . $unit . ', ' . $middle . ', ' . self::fmt($conv(max($mobile, $desktop))) . $unit . ')';
    }

    public static function fmt(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }
}
