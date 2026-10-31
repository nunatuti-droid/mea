<?php
declare(strict_types=1);

namespace Mea\Module\Design;

/**
 * Serverseitige Prüfung aller Designwerte. Jeder Wert wird gegen Typ und Bereich aus DesignSchema
 * geprüft und in eine feste Form gebracht (Kleinbuchstaben-HEX, Zahl mit Punkt). Freie CSS-Fragmente
 * gibt es nicht: Auswahlfelder liefern nur hinterlegte Werte, Zahlen werden neu formatiert.
 */
final class DesignValidator
{
    public function __construct(private FontLibrary $fonts) {}

    /**
     * @param array<string,mixed> $input Rohwerte (z. B. aus dem Formular); fehlende Schlüssel = Standardwert
     * @return array{0: array<string,mixed>, 1: array<string,string>} [Werte, Fehler je Schlüssel]
     */
    public function validate(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (DesignSchema::fields() as $f) {
            $key = $f['key'];
            if (!array_key_exists($key, $input) || $input[$key] === null) {
                $values[$key] = $f['default'];
                continue;
            }
            $raw = $input[$key];
            if (!is_scalar($raw)) {
                $errors[$key] = 'Ungültiger Wert.';
                $values[$key] = $f['default'];
                continue;
            }
            [$ok, $value, $message] = $this->check($f, trim((string) $raw));
            $values[$key] = $ok ? $value : $f['default'];
            if (!$ok) {
                $errors[$key] = $message;
            }
        }
        if (!isset($errors['color_focus']) && !isset($errors['color_background']) && $values['color_focus'] === $values['color_background']) {
            $errors['color_focus'] = 'Der Fokusrahmen darf nicht dieselbe Farbe wie der Hintergrund haben.';
        }
        return [$values, $errors];
    }

    /** @param array<string,mixed> $f @return array{0:bool,1:mixed,2:string} */
    public function check(array $f, string $raw): array
    {
        if (strlen($raw) > 64) {
            return [false, null, 'Der Wert ist zu lang.'];
        }
        switch ($f['type']) {
            case 'color':
                $v = strtolower($raw);
                if (preg_match('/^#?([0-9a-f]{3})$/', $v, $m)) {
                    $v = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
                } elseif (preg_match('/^#?([0-9a-f]{6})$/', $v, $m)) {
                    $v = '#' . $m[1];
                } else {
                    return [false, null, 'Bitte eine Farbe im HEX-Format angeben, z. B. #317566.'];
                }
                return [true, $v, ''];
            case 'px':
            case 'number':
            case 'em':
                $n = str_replace(',', '.', $raw);
                if (!preg_match('/^-?\d{1,4}(\.\d{1,4})?$/', $n)) {
                    return [false, null, 'Bitte eine Zahl eingeben.'];
                }
                $x = (float) $n;
                if ($x < $f['min'] || $x > $f['max']) {
                    $unit = $f['suffix'] ?? $f['unit'] ?? '';
                    return [false, null, sprintf('Erlaubt: %s bis %s%s.', self::num($f['min']), self::num($f['max']), $unit !== '' ? ' ' . $unit : '')];
                }
                $step = (float) ($f['step'] ?? 1);
                $x = round(round($x / $step) * $step, 4);
                return [true, $x == (int) $x && $step >= 1 ? (int) $x : $x, ''];
            case 'weight':
            case 'choice':
                if (!array_key_exists($raw, $f['options'])) {
                    return [false, null, 'Bitte einen Wert aus der Liste wählen.'];
                }
                return [true, $raw, ''];
            case 'font':
                if (!$this->fonts->has($raw)) {
                    return [false, null, 'Diese Schrift gibt es nicht (mehr).'];
                }
                return [true, $raw, ''];
        }
        return [false, null, 'Unbekannter Feldtyp.'];
    }

    /** Zahl ohne überflüssige Nullen, deutsches Komma (für Meldungen). */
    public static function num(int|float $n): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.'));
    }

    /**
     * Hinweise zur Lesbarkeit (kein Fehler): Kontrast nach WCAG 2.2, Ziel 4,5:1 für normalen Text.
     * @param array<string,mixed> $v @return list<string>
     */
    public static function warnings(array $v): array
    {
        $pairs = [
            ['color_text', 'color_background', 'Standard-Text auf dem Website-Hintergrund', 4.5],
            ['color_text', 'color_surface', 'Standard-Text auf Karten', 4.5],
            ['color_text_muted', 'color_surface', 'Nebentext auf Karten', 4.5],
            ['color_heading', 'color_surface', 'Überschriften auf Karten', 4.5],
            ['color_link', 'color_surface', 'Links auf Karten', 4.5],
            ['color_button_text', 'color_button', 'Button-Schrift auf dem Button', 4.5],
            ['color_button_text', 'color_button_hover', 'Button-Schrift beim Überfahren', 4.5],
            ['color_on_dark', 'color_secondary', 'Schrift auf der Sekundärfarbe', 4.5],
            ['color_on_dark_muted', 'color_secondary', 'Nebentext auf der Sekundärfarbe', 4.5],
            ['color_primary_strong', 'color_surface', '„Primärfarbe dunkel“ auf Karten', 4.5],
            ['color_error', 'color_surface', 'Fehlermeldungen', 4.5],
            ['color_focus', 'color_surface', 'Fokusrahmen auf Karten', 1.5],
        ];
        $out = [];
        foreach ($pairs as [$fg, $bg, $label, $min]) {
            $r = self::contrast((string) $v[$fg], (string) $v[$bg]);
            if ($r < $min) {
                $out[] = sprintf('%s: Kontrast %s:1 (empfohlen mindestens %s:1).', $label, self::num(round($r, 2)), self::num($min));
            }
        }
        return $out;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(fn($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
        $c = array_map(fn($x) => $x <= 0.04045 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
