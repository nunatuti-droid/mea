<?php
declare(strict_types=1);

namespace Mea\Module\Design;

/**
 * Verfügbare Schriften. Alle Webfonts liegen auf dem eigenen Server (public/assets/fonts, SIL OFL);
 * es wird nichts von Google oder anderen Anbietern nachgeladen (Datenschutz).
 * Eigene Schriften lädt man in der Verwaltung hoch (Tabelle design_fonts, Dateien in public/media/fonts).
 */
final class FontLibrary
{
    public const SANS = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif';
    public const SERIF = 'Georgia, "Times New Roman", serif';

    /** Unicode-Bereiche der Dateien „latin“ und „latin-ext“ */
    private const RANGES = [
        'latin' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD',
        'latin-ext' => 'U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF',
    ];

    /** Mitgelieferte Schriften: Kennung => [Name, Dateipräfix, Gewichte, Ersatz] */
    private const BUNDLED = [
        'plus-jakarta' => ['Plus Jakarta Sans', 'plus-jakarta-sans', '200 800', 'sans'],
        'inter' => ['Inter', 'inter', '100 900', 'sans'],
        'open-sans' => ['Open Sans', 'open-sans', '300 800', 'sans'],
        'source-sans-3' => ['Source Sans 3', 'source-sans-3', '200 900', 'sans'],
        'nunito' => ['Nunito', 'nunito', '200 900', 'sans'],
        'lora' => ['Lora', 'lora', '400 700', 'serif'],
        'source-serif-4' => ['Source Serif 4', 'source-serif-4', '200 900', 'serif'],
        'merriweather' => ['Merriweather', 'merriweather', '300 900', 'serif'],
    ];

    private const SYSTEM = [
        'system-sans' => ['Systemschrift ohne Serifen (kein Download)', self::SANS],
        'system-serif' => ['Systemschrift mit Serifen, z. B. Georgia (kein Download)', self::SERIF],
    ];

    /** @param list<array<string,mixed>> $custom Zeilen aus design_fonts */
    public function __construct(private array $custom = []) {}

    /** @return array<string,array{label:string, family:?string, stack:string, kind:string, faces:list<array<string,string>>, preload:?string}> */
    public function all(): array
    {
        $out = [];
        foreach (self::BUNDLED as $id => [$name, $file, $weights, $fallback]) {
            $faces = [];
            foreach (self::RANGES as $subset => $range) {
                $faces[] = ['url' => "/assets/fonts/$file-$subset-wght-normal.woff2", 'format' => 'woff2-variations', 'weight' => $weights, 'style' => 'normal', 'range' => $range];
            }
            $out[$id] = ['label' => $name, 'family' => $name, 'stack' => self::quote($name) . ', ' . ($fallback === 'serif' ? self::SERIF : self::SANS),
                'kind' => 'Webfont (lokal)', 'faces' => $faces, 'preload' => "/assets/fonts/$file-latin-wght-normal.woff2"];
        }
        foreach (self::SYSTEM as $id => [$label, $stack]) {
            $out[$id] = ['label' => $label, 'family' => null, 'stack' => $stack, 'kind' => 'Systemschrift', 'faces' => [], 'preload' => null];
        }
        foreach ($this->custom as $c) {
            $family = (string) $c['family'];
            $weight = (int) $c['weight_min'] === (int) $c['weight_max'] ? (string) (int) $c['weight_min'] : (int) $c['weight_min'] . ' ' . (int) $c['weight_max'];
            $url = '/media/fonts/' . $c['file'];
            $out['custom-' . (int) $c['id']] = ['label' => $family . ' (eigene Schrift)', 'family' => $family,
                'stack' => self::quote($family) . ', ' . ($c['fallback'] === 'serif' ? self::SERIF : self::SANS), 'kind' => 'Eigene Schrift',
                'faces' => [['url' => $url, 'format' => str_ends_with($url, '.woff') ? 'woff' : 'woff2', 'weight' => $weight, 'style' => (string) $c['style'], 'range' => '']],
                'preload' => null];
        }
        return $out;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function stack(string $id): string
    {
        return $this->all()[$id]['stack'] ?? self::SANS;
    }

    /** Familienname einer mitgelieferten Schrift (damit eigene Schriften keinen gleichen Namen bekommen). */
    public static function bundledFamilies(): array
    {
        return array_map(fn($b) => strtolower($b[0]), array_values(self::BUNDLED));
    }

    /** @return array<string,string> Kennung => Bezeichnung für Auswahllisten */
    public function options(): array
    {
        return array_map(fn($f) => $f['label'], $this->all());
    }

    /** @-font-face-Regeln aller Schriften. Browser laden eine Datei erst, wenn die Schrift auch verwendet wird. */
    public function fontFaceCss(): string
    {
        $css = '';
        foreach ($this->all() as $font) {
            foreach ($font['faces'] as $face) {
                $css .= '@font-face { font-family: ' . self::quote((string) $font['family']) . '; font-style: ' . $face['style'] . '; font-weight: ' . $face['weight']
                    . '; font-display: swap; src: url("' . $face['url'] . '") format("' . $face['format'] . '");'
                    . ($face['range'] !== '' ? ' unicode-range: ' . $face['range'] . ';' : '') . " }\n";
            }
        }
        return $css;
    }

    /** Erlaubt sind nur Buchstaben, Ziffern, Leerzeichen und Bindestriche; damit ist der Name in CSS sicher. */
    public static function quote(string $family): string
    {
        return '"' . preg_replace('/[^A-Za-z0-9 \-]/', '', $family) . '"';
    }
}
