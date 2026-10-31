<?php
declare(strict_types=1);

namespace Mea\Module\Design;

use Mea\Core\Database;
use Mea\Module\Content\SettingsRepository;

/**
 * Designsystem: liest die gespeicherten Abweichungen (design_tokens), speichert neue Werte und
 * veröffentlicht die erzeugten CSS-Variablen als statische Datei public/media/design/design-<hash>.css.
 *
 * Cache: Der Dateiname enthält eine Prüfsumme des Inhalts. Nach jeder Änderung entsteht eine neue
 * Datei, die Seiten verweisen sofort darauf; Browser und Webserver dürfen jede Datei ein Jahr lang
 * zwischenspeichern. Welche Datei aktuell ist, steht in der Einstellung „design_cache“, die ohnehin
 * bei jeder Anfrage geladen wird – ein Seitenaufruf kostet also keine zusätzliche Datenbankabfrage.
 */
final class DesignService
{
    public const CACHE_KEY = 'design_cache';
    public const FALLBACK_URL = '/designsystem.css';
    private ?FontLibrary $fonts = null;
    /** @var array<string,mixed>|null */
    private ?array $meta = null;

    public function __construct(
        private Database $db,
        private SettingsRepository $settings,
        private string $dir,
        private ?\Psr\Log\LoggerInterface $log = null,
    ) {}

    public function fonts(): FontLibrary
    {
        return $this->fonts ??= new FontLibrary($this->db->all('SELECT * FROM design_fonts ORDER BY family, id'));
    }

    public function validator(): DesignValidator
    {
        return new DesignValidator($this->fonts());
    }

    public function css(): DesignCss
    {
        return new DesignCss($this->fonts());
    }

    /** Gespeicherte Abweichungen von den Standardwerten (ungültige oder veraltete Einträge werden übergangen). @return array<string,mixed> */
    public function overrides(): array
    {
        $fields = DesignSchema::byKey();
        $validator = $this->validator();
        $out = [];
        foreach ($this->db->all('SELECT token_key, value_json FROM design_tokens') as $row) {
            $f = $fields[$row['token_key']] ?? null;
            $raw = json_decode((string) $row['value_json'], true);
            if ($f === null || !is_scalar($raw)) {
                continue;
            }
            [$ok, $value] = $validator->check($f, (string) $raw);
            if ($ok) {
                $out[$f['key']] = $value;
            }
        }
        return $out;
    }

    /** Wirksame Werte: Standardwerte, überschrieben von den gespeicherten Abweichungen. @return array<string,mixed> */
    public function values(): array
    {
        return array_merge(DesignSchema::defaults(), $this->overrides());
    }

    /**
     * Geprüfte Werte speichern. Werte gleich dem Standard werden nicht gespeichert.
     * @param array<string,mixed> $values @return array<string,array{0:mixed,1:mixed}> Änderungen [vorher, nachher]
     */
    public function save(array $values, ?int $userId): array
    {
        $defaults = DesignSchema::defaults();
        $before = $this->values();
        $changes = [];
        $this->db->transaction(function (Database $db) use ($values, $defaults, $before, $userId, &$changes): void {
            foreach ($defaults as $key => $default) {
                $new = $values[$key] ?? $default;
                if (self::same($new, $default)) {
                    $db->run('DELETE FROM design_tokens WHERE token_key = ?', [$key]);
                } else {
                    $db->run('INSERT INTO design_tokens (token_key, value_json, updated_by) VALUES (?, ?, ?)
                              ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), updated_by = VALUES(updated_by)',
                        [$key, json_encode($new, JSON_THROW_ON_ERROR), $userId]);
                }
                if (!self::same($new, $before[$key])) {
                    $changes[$key] = [$before[$key], $new];
                }
            }
        });
        $this->publish();
        return $changes;
    }

    /** Alle Abweichungen löschen: die Website nutzt wieder die Standardwerte. Liefert die Zahl gelöschter Werte. */
    public function reset(): int
    {
        $n = $this->db->run('DELETE FROM design_tokens');
        $this->publish();
        return $n;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.00001 : (string) $a === (string) $b;
    }

    // ---------- Ausgabe ----------

    /** Adresse der aktuellen CSS-Datei; erzeugt sie, falls sie fehlt oder veraltet ist (z. B. nach einem Update). */
    public function stylesheetUrl(): string
    {
        $m = $this->meta();
        if (($m['file'] ?? null) !== null) {
            return '/media/design/' . $m['file'];
        }
        return self::FALLBACK_URL . '?v=' . ($m['hash'] ?? '0');
    }

    /** @return list<string> Schriftdateien, die der Browser früh laden soll (Überschrift und Fließtext) */
    public function preloads(): array
    {
        return $this->meta()['preload'] ?? [];
    }

    public function themeColor(): string
    {
        return (string) ($this->meta()['theme'] ?? DesignSchema::defaults()['color_secondary']);
    }

    /** @return array<string,mixed> */
    private function meta(): array
    {
        if ($this->meta !== null) {
            return $this->meta;
        }
        $m = $this->settings->get(self::CACHE_KEY);
        $valid = is_array($m) && ($m['fp'] ?? '') === DesignSchema::fingerprint()
            && (($m['file'] ?? null) === null ? isset($m['hash']) : is_file($this->dir . '/' . basename((string) $m['file'])));
        if (!$valid) {
            try {
                $m = $this->publish();
            } catch (\Throwable $e) {
                $this->log?->error('Designsystem: CSS konnte nicht erzeugt werden', ['error' => $e->getMessage()]);
                $m = ['file' => null, 'hash' => '0', 'preload' => [], 'theme' => DesignSchema::defaults()['color_secondary']];
            }
        }
        return $this->meta = $m;
    }

    /** Vollständiges Stylesheet für die Ausweich-Adresse /designsystem.css */
    public function stylesheet(): string
    {
        return $this->css()->stylesheet($this->values());
    }

    /**
     * CSS neu erzeugen und als Datei ablegen (Cache-Invalidierung). Ist der Ordner nicht beschreibbar,
     * liefert die Anwendung das CSS über /designsystem.css aus.
     * @return array<string,mixed>
     */
    public function publish(): array
    {
        $this->fonts = null;
        $values = $this->values();
        $css = $this->css()->stylesheet($values);
        $hash = substr(hash('sha256', $css), 0, 16);
        $file = 'design-' . $hash . '.css';
        $written = false;
        if ((is_dir($this->dir) || @mkdir($this->dir, 0755, true)) && is_writable($this->dir)) {
            $tmp = $this->dir . '/.tmp-' . bin2hex(random_bytes(6));
            if (@file_put_contents($tmp, $css) !== false && @rename($tmp, $this->dir . '/' . $file)) {
                $written = true;
                @copy($this->dir . '/' . $file, $this->dir . '/aktuell.css');
                $this->cleanup($file);
            } else {
                @unlink($tmp);
            }
        }
        if (!$written) {
            $this->log?->warning('Designsystem: Ordner nicht beschreibbar, CSS wird über ' . self::FALLBACK_URL . ' ausgeliefert', ['dir' => $this->dir]);
        }
        $lib = $this->fonts()->all();
        $preload = [];
        foreach (['font_heading', 'font_body'] as $k) {
            $p = $lib[$values[$k]]['preload'] ?? null;
            if ($p !== null && !in_array($p, $preload, true)) {
                $preload[] = $p;
            }
        }
        $meta = ['file' => $written ? $file : null, 'hash' => $hash, 'fp' => DesignSchema::fingerprint(), 'preload' => $preload,
            'theme' => $values['color_secondary'], 'published_at' => gmdate('c')];
        $this->settings->set(self::CACHE_KEY, $meta);
        return $this->meta = $meta;
    }

    /** Ältere Dateien entfernen; die zwei vorigen bleiben für Seiten, die noch im Browser offen sind. */
    private function cleanup(string $current): void
    {
        $files = glob($this->dir . '/design-*.css') ?: [];
        usort($files, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $keep = 0;
        foreach ($files as $f) {
            if (basename($f) === $current) {
                continue;
            }
            if (++$keep > 2) {
                @unlink($f);
            }
        }
    }

    // ---------- Eigene Schriften ----------

    public const FONT_MAX_BYTES = 2_000_000;

    /**
     * Eigene Schrift (WOFF2 oder WOFF) prüfen und ablegen.
     * @return array{0:?int, 1:list<string>} [neue ID, Fehler]
     */
    public function addFont(string $tmpFile, string $originalName, string $family, int $weightMin, int $weightMax, string $style, string $fallback, string $fontDir): array
    {
        $errors = [];
        $family = trim(preg_replace('/\s+/', ' ', $family) ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9 \-]{1,39}$/', $family)) {
            $errors[] = 'Name der Schrift: 2 bis 40 Zeichen, nur Buchstaben (ohne Umlaute), Ziffern, Leerzeichen und Bindestriche.';
        } elseif (in_array(strtolower($family), FontLibrary::bundledFamilies(), true)) {
            $errors[] = 'Diese Schrift ist schon mitgeliefert. Bitte einen anderen Namen wählen.';
        }
        if ($weightMin < 100 || $weightMax > 900 || $weightMin > $weightMax) {
            $errors[] = 'Schriftgewicht: von 100 bis 900, „von“ nicht größer als „bis“.';
        }
        if (!in_array($style, ['normal', 'italic'], true) || !in_array($fallback, ['sans', 'serif'], true)) {
            $errors[] = 'Ungültige Auswahl bei Stil oder Ersatzschrift.';
        }
        $size = is_file($tmpFile) ? (int) filesize($tmpFile) : 0;
        $magic = $size >= 4 ? (string) file_get_contents($tmpFile, false, null, 0, 4) : '';
        $ext = match ($magic) {
            'wOF2' => 'woff2',
            'wOFF' => 'woff',
            default => null,
        };
        if ($size === 0) {
            $errors[] = 'Bitte eine Schriftdatei auswählen.';
        } elseif ($size > self::FONT_MAX_BYTES) {
            $errors[] = 'Die Datei ist größer als 2 MB.';
        } elseif ($ext === null) {
            $errors[] = 'Nur Webfonts im Format WOFF2 oder WOFF sind erlaubt (der Inhalt wird geprüft, nicht nur die Endung).';
        }
        if ($errors === [] && $this->db->value('SELECT 1 FROM design_fonts WHERE family = ? AND style = ?', [$family, $style]) !== null) {
            $errors[] = 'Eine Schrift mit diesem Namen und Stil gibt es schon.';
        }
        if ($errors !== []) {
            return [null, $errors];
        }
        if (!is_dir($fontDir) && !@mkdir($fontDir, 0755, true)) {
            return [null, ['Der Schriften-Ordner ist nicht beschreibbar.']];
        }
        $name = substr(hash_file('sha256', $tmpFile) ?: bin2hex(random_bytes(16)), 0, 24) . '.' . $ext;
        if (!@copy($tmpFile, $fontDir . '/' . $name)) {
            return [null, ['Die Datei konnte nicht gespeichert werden.']];
        }
        $id = $this->db->insert('design_fonts', [
            'family' => $family, 'file' => $name, 'weight_min' => $weightMin, 'weight_max' => $weightMax,
            'style' => $style, 'fallback' => $fallback, 'original_name' => mb_substr($originalName, 0, 200),
        ]);
        $this->publish();
        return [$id, []];
    }

    /** @return list<string> Fehler (Schrift wird noch verwendet) */
    public function deleteFont(int $id, string $fontDir): array
    {
        $row = $this->db->one('SELECT * FROM design_fonts WHERE id = ?', [$id]);
        if ($row === null) {
            return ['Die Schrift gibt es nicht mehr.'];
        }
        $used = array_keys(array_filter($this->values(), fn($v) => $v === 'custom-' . $id));
        if ($used !== []) {
            $labels = array_map(fn($k) => DesignSchema::byKey()[$k]['label'], $used);
            return ['Die Schrift wird noch verwendet (' . implode(', ', $labels) . '). Bitte dort zuerst eine andere Schrift wählen und speichern.'];
        }
        $this->db->run('DELETE FROM design_fonts WHERE id = ?', [$id]);
        if ($this->db->value('SELECT 1 FROM design_fonts WHERE file = ?', [$row['file']]) === null) {
            @unlink($fontDir . '/' . basename((string) $row['file']));
        }
        $this->publish();
        return [];
    }
}
