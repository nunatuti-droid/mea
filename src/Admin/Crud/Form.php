<?php
declare(strict_types=1);

namespace Mea\Admin\Crud;

use Mea\Core\HtmlSanitizer;
use Mea\Core\Request;

/**
 * Formularfelder als Daten: Lesen, Prüfen und Umwandeln in Datenbankwerte.
 * Feldtypen: text, slug, textarea, html, int, money, select, checkbox, date, datetime,
 * time, email, tel, url, media, lines (Liste, als JSON gespeichert).
 *
 * Felddefinition: ['name', 'label', 'type', 'required'?, 'max'?, 'min'?, 'help'?, 'options'?, 'group'?, 'nullable'?, 'readonly'?]
 */
final class Form
{
    private const TZ = 'Europe/Berlin';

    /**
     * @param list<array<string,mixed>> $fields
     * @return array{0: array<string,mixed>, 1: array<string,string>, 2: array<string,mixed>} [DB-Werte, Fehler, Formularwerte]
     */
    public static function read(Request $req, array $fields, HtmlSanitizer $html): array
    {
        $values = [];
        $errors = [];
        $raw = [];
        foreach ($fields as $f) {
            if (!empty($f['readonly'])) {
                continue;
            }
            $name = (string) $f['name'];
            $type = (string) ($f['type'] ?? 'text');
            $label = (string) $f['label'];
            $required = (bool) ($f['required'] ?? false);
            if ($type === 'multi') {
                $opts = array_map('strval', array_keys((array) ($f['options'] ?? [])));
                $sel = array_values(array_intersect($opts, $req->inputList($name)));
                $raw[$name] = $sel;
                $values[$name] = $sel === [] ? null : json_encode($sel, JSON_UNESCAPED_UNICODE);
                if ($required && $sel === []) {
                    $errors[$name] = "Bitte mindestens eine Auswahl bei „{$label}“ treffen.";
                }
                continue;
            }
            $in = $type === 'checkbox' ? ($req->input($name) === '1' ? '1' : '') : ($type === 'html' ? (string) ($req->post[$name] ?? '') : $req->input($name));
            $raw[$name] = $in;
            if ($in === '' && $type !== 'checkbox') {
                if ($required) {
                    $errors[$name] = "Bitte „{$label}“ ausfüllen.";
                }
                $values[$name] = ($f['nullable'] ?? !$required) ? null : '';
                if ($type === 'lines') {
                    $values[$name] = null;
                }
                continue;
            }
            $max = (int) ($f['max'] ?? 0);
            switch ($type) {
                case 'checkbox':
                    $values[$name] = $in === '1' ? 1 : 0;
                    break;
                case 'int':
                    if (!preg_match('/^-?\d+$/', $in)) {
                        $errors[$name] = "„{$label}“ muss eine ganze Zahl sein.";
                        break;
                    }
                    $v = (int) $in;
                    if (isset($f['min']) && $v < (int) $f['min']) {
                        $errors[$name] = "„{$label}“ muss mindestens {$f['min']} sein.";
                    } elseif (isset($f['maxValue']) && $v > (int) $f['maxValue']) {
                        $errors[$name] = "„{$label}“ darf höchstens {$f['maxValue']} sein.";
                    }
                    $values[$name] = $v;
                    break;
                case 'money':
                    $cents = self::parseMoney($in);
                    if ($cents === null) {
                        $errors[$name] = "„{$label}“: bitte einen Betrag wie 150,00 eingeben.";
                    }
                    $values[$name] = $cents;
                    break;
                case 'select':
                    $opts = array_map('strval', array_keys((array) ($f['options'] ?? [])));
                    if (!in_array($in, $opts, true)) {
                        $errors[$name] = "Bitte für „{$label}“ eine gültige Auswahl treffen.";
                    }
                    $values[$name] = preg_match('/^\d+$/', $in) && ($f['numeric'] ?? false) ? (int) $in : $in;
                    break;
                case 'media':
                    $values[$name] = preg_match('/^\d+$/', $in) ? (int) $in : null;
                    break;
                case 'slug':
                    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $in) || strlen($in) > 120) {
                        $errors[$name] = "„{$label}“ darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.";
                    }
                    $values[$name] = $in;
                    break;
                case 'email':
                    if (!filter_var($in, FILTER_VALIDATE_EMAIL)) {
                        $errors[$name] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
                    }
                    $values[$name] = mb_strtolower($in);
                    break;
                case 'url':
                    if (!preg_match('#^(/[^\s]*|https?://[^\s]+|mailto:[^\s]+|tel:[+0-9]+)$#', $in)) {
                        $errors[$name] = "„{$label}“: bitte einen Pfad wie /kontakt/ oder eine vollständige Adresse eingeben.";
                    }
                    $values[$name] = $in;
                    break;
                case 'date':
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $in);
                    if ($d === false) {
                        $errors[$name] = "„{$label}“: ungültiges Datum.";
                    }
                    $values[$name] = $d ? $d->format('Y-m-d') : null;
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in)) {
                        $errors[$name] = "„{$label}“: ungültige Uhrzeit.";
                    }
                    $values[$name] = $in . ':00';
                    break;
                case 'datetime':
                    $values[$name] = self::localToUtc($in);
                    if ($values[$name] === null) {
                        $errors[$name] = "„{$label}“: ungültiges Datum oder Uhrzeit.";
                    }
                    break;
                case 'html':
                    $values[$name] = $html->clean($in);
                    break;
                case 'lines':
                    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $in) ?: []), fn($l) => $l !== ''));
                    $values[$name] = $lines === [] ? null : json_encode($lines, JSON_UNESCAPED_UNICODE);
                    break;
                default:
                    $values[$name] = $type === 'textarea' ? str_replace("\r\n", "\n", $in) : preg_replace('/\s+/u', ' ', $in);
            }
            if ($max > 0 && in_array($type, ['text', 'textarea', 'email', 'tel', 'url'], true) && mb_strlen((string) $values[$name]) > $max) {
                $errors[$name] = "„{$label}“ darf höchstens $max Zeichen lang sein.";
            }
        }
        return [$values, $errors, $raw];
    }

    /**
     * Datenbankwerte in Formularwerte umwandeln.
     * @param list<array<string,mixed>> $fields @param array<string,mixed> $row @return array<string,mixed>
     */
    public static function display(array $fields, array $row): array
    {
        $out = [];
        foreach ($fields as $f) {
            $name = (string) $f['name'];
            $v = $row[$name] ?? ($f['default'] ?? null);
            $out[$name] = match ((string) ($f['type'] ?? 'text')) {
                'money' => $v === null || $v === '' ? '' : number_format(((int) $v) / 100, 2, ',', ''),
                'datetime' => $v ? self::utcToLocal((string) $v) : '',
                'time' => $v ? substr((string) $v, 0, 5) : '',
                'lines' => $v ? implode("\n", (array) (is_string($v) ? json_decode($v, true) : $v)) : '',
                'checkbox' => (string) (int) $v,
                'multi' => is_array($v) ? array_map('strval', $v) : (is_string($v) && $v !== '' ? array_map('strval', (array) json_decode($v, true)) : []),
                default => $v === null ? '' : (string) $v,
            };
        }
        return $out;
    }

    public static function parseMoney(string $in): ?int
    {
        $s = str_replace([' ', '€'], '', $in);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $s)) {
            return null;
        }
        return (int) round(((float) $s) * 100);
    }

    public static function localToUtc(string $local): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new \DateTimeZone(self::TZ));
        return $d === false ? null : $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function utcToLocal(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TZ))->format('Y-m-d\TH:i');
    }

    /** Aus einem Titel einen URL-Slug machen (Umlaute ausgeschrieben). */
    public static function slugify(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^a-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s) ?? '';
        return trim($s, '-');
    }
}
