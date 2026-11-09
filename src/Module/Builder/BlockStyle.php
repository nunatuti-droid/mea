<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Module\Design\DesignSchema;
use Mea\Module\Design\FontLibrary;

/**
 * Stil eines einzelnen Elements, getrennt nach Computer (d), Tablet (t) und Handy (m).
 * Leere Werte bedeuten: Wert aus dem Designsystem. Jede Angabe wird geprüft und als CSS-Regel für die Klasse .b-<id> ausgegeben,
 * nie als style-Attribut (Content-Security-Policy erlaubt nur Stylesheets der eigenen Website).
 */
final class BlockStyle
{
    public const DEVICES = ['d' => 'Computer', 't' => 'Tablet', 'm' => 'Handy'];

    public const GROUPS = [
        'typo' => 'Schrift',
        'colors' => 'Farben',
        'spacing' => 'Abstände',
        'size' => 'Größe',
        'border' => 'Rahmen und Schatten',
        'layout' => 'Anordnung',
        'visibility' => 'Sichtbarkeit',
    ];

    /** @return array<string,array<string,mixed>> */
    public static function props(): array
    {
        $px = fn(string $label, string $group, int $min, int $max, string $css) => ['label' => $label, 'group' => $group, 'type' => 'px', 'min' => $min, 'max' => $max, 'css' => $css];
        return [
            'font' => ['label' => 'Schriftfamilie', 'group' => 'typo', 'type' => 'font', 'css' => 'font-family'],
            'size' => ['label' => 'Schriftgröße', 'group' => 'typo', 'type' => 'px', 'min' => 8, 'max' => 200, 'css' => 'font-size', 'rem' => true],
            'weight' => ['label' => 'Schriftstärke', 'group' => 'typo', 'type' => 'select', 'options' => array_combine($w = array_map('strval', range(100, 900, 100)), $w), 'css' => 'font-weight'],
            'lh' => ['label' => 'Zeilenhöhe', 'group' => 'typo', 'type' => 'number', 'min' => 0.8, 'max' => 3, 'step' => 0.05, 'css' => 'line-height'],
            'ls' => ['label' => 'Buchstabenabstand (em)', 'group' => 'typo', 'type' => 'number', 'min' => -0.1, 'max' => 0.5, 'step' => 0.005, 'css' => 'letter-spacing', 'unit' => 'em'],
            'tt' => ['label' => 'Schreibweise', 'group' => 'typo', 'type' => 'select', 'options' => ['none' => 'normal', 'uppercase' => 'GROSS', 'lowercase' => 'klein', 'capitalize' => 'Wortanfänge groß'], 'css' => 'text-transform'],
            'fs' => ['label' => 'Schriftstil', 'group' => 'typo', 'type' => 'select', 'options' => ['normal' => 'normal', 'italic' => 'kursiv'], 'css' => 'font-style'],
            'align' => ['label' => 'Textausrichtung', 'group' => 'typo', 'type' => 'select', 'options' => ['left' => 'links', 'center' => 'Mitte', 'right' => 'rechts', 'justify' => 'Blocksatz'], 'css' => 'text-align'],
            'color' => ['label' => 'Schriftfarbe', 'group' => 'colors', 'type' => 'color', 'css' => 'color'],
            'bg' => ['label' => 'Hintergrundfarbe', 'group' => 'colors', 'type' => 'color', 'css' => 'background-color'],
            'opacity' => ['label' => 'Deckkraft (%)', 'group' => 'colors', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => 1, 'css' => 'opacity'],
            'pt' => $px('Innenabstand oben', 'spacing', 0, 400, 'padding-top'),
            'pr' => $px('Innenabstand rechts', 'spacing', 0, 400, 'padding-right'),
            'pb' => $px('Innenabstand unten', 'spacing', 0, 400, 'padding-bottom'),
            'pl' => $px('Innenabstand links', 'spacing', 0, 400, 'padding-left'),
            'mt' => $px('Außenabstand oben', 'spacing', -200, 400, 'margin-top'),
            'mb' => $px('Außenabstand unten', 'spacing', -200, 400, 'margin-bottom'),
            'w' => ['label' => 'Breite (%)', 'group' => 'size', 'type' => 'number', 'min' => 1, 'max' => 100, 'step' => 1, 'css' => 'width', 'unit' => '%'],
            'maxw' => $px('Maximale Breite', 'size', 0, 2400, 'max-width'),
            'h' => $px('Höhe', 'size', 0, 2000, 'height'),
            'minh' => $px('Minimale Höhe', 'size', 0, 2000, 'min-height'),
            'mx' => ['label' => 'Horizontal', 'group' => 'size', 'type' => 'select', 'options' => ['start' => 'links', 'center' => 'zentriert', 'end' => 'rechts'], 'css' => 'margin-inline'],
            'bw' => ['label' => 'Rahmenstärke', 'group' => 'border', 'type' => 'px', 'min' => 0, 'max' => 20, 'step' => 0.5, 'css' => 'border-width'],
            'bs' => ['label' => 'Rahmenart', 'group' => 'border', 'type' => 'select', 'options' => ['solid' => 'durchgezogen', 'dashed' => 'gestrichelt', 'dotted' => 'gepunktet'], 'css' => 'border-style'],
            'bc' => ['label' => 'Rahmenfarbe', 'group' => 'border', 'type' => 'color', 'css' => 'border-color'],
            'radius' => $px('Eckradius', 'border', 0, 999, 'border-radius'),
            'shadow' => ['label' => 'Schatten', 'group' => 'border', 'type' => 'select', 'options' => ['none' => 'keiner', 'small' => 'klein', 'card' => 'Karte', 'raised' => 'angehoben'], 'css' => 'box-shadow'],
            'gap' => $px('Abstand zwischen Elementen', 'layout', 0, 200, 'gap'),
            'dir' => ['label' => 'Richtung', 'group' => 'layout', 'type' => 'select', 'options' => ['row' => 'nebeneinander', 'column' => 'untereinander'], 'css' => 'flex-direction', 'only' => ['group']],
            'ai' => ['label' => 'Ausrichtung quer', 'group' => 'layout', 'type' => 'select', 'options' => ['start' => 'Anfang', 'center' => 'Mitte', 'end' => 'Ende', 'stretch' => 'strecken'], 'css' => 'align-items', 'only' => ['group', 'columns', 'buttons']],
            'jc' => ['label' => 'Verteilung', 'group' => 'layout', 'type' => 'select', 'options' => ['start' => 'Anfang', 'center' => 'Mitte', 'end' => 'Ende', 'space-between' => 'verteilt'], 'css' => 'justify-content', 'only' => ['group', 'buttons']],
            'cols' => ['label' => 'Spalten', 'group' => 'layout', 'type' => 'select', 'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4'], 'css' => 'grid-template-columns', 'only' => ['columns', 'cards', 'gallery', 'stats']],
            'hide' => ['label' => 'Auf diesem Gerät ausblenden', 'group' => 'visibility', 'type' => 'bool', 'css' => 'display'],
        ];
    }

    /** Farben aus dem Designsystem, die ein Element verwenden kann: Schlüssel → [Bezeichnung, CSS-Variable]. @return array<string,array{0:string,1:string}> */
    public static function tokens(): array
    {
        static $t = null;
        if ($t !== null) {
            return $t;
        }
        $t = [];
        foreach (DesignSchema::fields() as $f) {
            if ($f['type'] === 'color' && !empty($f['var'])) {
                $t[substr((string) $f['var'], 2)] = [(string) $f['label'], (string) $f['var']];
            }
        }
        return $t;
    }

    /**
     * Prüft einen Stil; unbekannte Angaben fallen weg.
     * @param mixed $style @param list<string> $errors @return array<string,array<string,mixed>>
     */
    public static function clean(mixed $style, string $type, FontLibrary $fonts, array &$errors): array
    {
        if (!is_array($style)) {
            return [];
        }
        $props = self::props();
        $out = [];
        foreach (self::DEVICES as $dev => $devLabel) {
            $vals = $style[$dev] ?? null;
            if (!is_array($vals)) {
                continue;
            }
            foreach ($vals as $key => $raw) {
                $p = $props[$key] ?? null;
                if ($p === null || $raw === '' || $raw === null || (isset($p['only']) && !in_array($type, $p['only'], true))) {
                    continue;
                }
                $v = self::value($p, $raw, $fonts);
                if ($v === null) {
                    $errors[] = $p['label'] . ' (' . $devLabel . '): ungültiger Wert';
                    continue;
                }
                $out[$dev][$key] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $p */
    private static function value(array $p, mixed $raw, FontLibrary $fonts): mixed
    {
        if (is_array($raw) || is_object($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        if (strlen($s) > 40) {
            return null;
        }
        switch ($p['type']) {
            case 'bool':
                return $raw === true || $s === '1' || $s === 'true' ? true : null;
            case 'select':
                return array_key_exists($s, $p['options']) ? $s : null;
            case 'font':
                return in_array($s, ['heading', 'body', 'ui'], true) || $fonts->has($s) ? $s : null;
            case 'color':
                if (preg_match('/^#[0-9a-f]{6}$/i', $s)) {
                    return strtolower($s);
                }
                return str_starts_with($s, 'var:') && isset(self::tokens()[substr($s, 4)]) ? $s : null;
            case 'px':
            case 'number':
                $s = str_replace(',', '.', $s);
                if (!preg_match('/^-?\d{1,4}(\.\d{1,3})?$/', $s)) {
                    return null;
                }
                $n = (float) $s;
                return $n < $p['min'] || $n > $p['max'] ? null : $n + 0;
        }
        return null;
    }

    /**
     * CSS-Regeln für ein Element.
     * @param array<string,array<string,mixed>> $style @param array{tablet:int, mobile:int} $bp
     */
    public static function css(string $id, array $style, array $bp, FontLibrary $fonts): string
    {
        $sel = '.b-' . $id . '.b-' . $id;
        $props = self::props();
        $out = '';
        foreach (['d' => null, 't' => '(max-width: ' . $bp['tablet'] . 'px)', 'm' => '(max-width: ' . $bp['mobile'] . 'px)'] as $dev => $media) {
            $decl = [];
            foreach ($style[$dev] ?? [] as $key => $v) {
                if ($key === 'hide') {
                    continue;
                }
                $decl[] = self::declaration($props[$key], $key, $v, $fonts);
            }
            if (isset($style[$dev]['bw']) && !isset($style[$dev]['bs']) && !isset($style['d']['bs'])) {
                $decl[] = 'border-style: solid;';
            }
            if ($decl !== []) {
                $rule = $sel . ' { ' . implode(' ', $decl) . ' }';
                $out .= $media === null ? $rule . "\n" : "@media $media { $rule }\n";
            }
        }
        // Ausblenden gilt nur im Bereich des jeweiligen Geräts
        $ranges = ['d' => '(min-width: ' . ($bp['tablet'] + 1) . 'px)', 't' => '(min-width: ' . ($bp['mobile'] + 1) . 'px) and (max-width: ' . $bp['tablet'] . 'px)', 'm' => '(max-width: ' . $bp['mobile'] . 'px)'];
        foreach ($ranges as $dev => $media) {
            if (!empty($style[$dev]['hide'])) {
                $out .= "@media $media { $sel { display: none !important; } }\n";
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $p */
    private static function declaration(array $p, string $key, mixed $v, FontLibrary $fonts): string
    {
        $css = $p['css'];
        $value = match ($p['type']) {
            'color' => str_starts_with((string) $v, 'var:') ? 'var(--' . substr((string) $v, 4) . ')' : (string) $v,
            'font' => in_array($v, ['heading', 'body', 'ui'], true) ? 'var(--font-family-' . $v . ')' : $fonts->stack((string) $v),
            'px' => !empty($p['rem']) ? self::fmt((float) $v / 16) . 'rem' : self::fmt((float) $v) . 'px',
            'number' => $key === 'opacity' ? self::fmt((float) $v / 100) : self::fmt((float) $v) . ($p['unit'] ?? ''),
            default => (string) $v,
        };
        return match ($key) {
            'shadow' => 'box-shadow: ' . ($v === 'none' ? 'none' : 'var(--shadow-' . $v . ')') . ';',
            'mx' => 'margin-inline: ' . ['start' => '0 auto', 'center' => 'auto', 'end' => 'auto 0'][$v] . ';',
            'cols' => 'grid-template-columns: repeat(' . (int) $v . ', minmax(0, 1fr));',
            'ai', 'jc' => $css . ': ' . (in_array($v, ['start', 'end'], true) ? 'flex-' . $v : $v) . ';',
            default => $css . ': ' . $value . ';',
        };
    }

    private static function fmt(float $n): string
    {
        $s = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
}
