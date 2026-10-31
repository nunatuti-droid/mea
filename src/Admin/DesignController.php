<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Design\DesignSchema;
use Mea\Module\Design\DesignValidator;

/**
 * Verwaltung → Einstellungen → Designsystem: Typografie, Farben, Layout, Komponenten, Responsive.
 * Recht „design“ (nur Administratoren). Jede Änderung landet im Änderungsprotokoll.
 */
final class DesignController
{
    private const BASE = '/verwaltung/designsystem/';

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get(self::BASE, 'design', $this->edit(...));
        $k->post(self::BASE, 'design', $this->save(...));
        $k->post(self::BASE . 'vorschau/', 'design', $this->preview(...));
        $k->post(self::BASE . 'zuruecksetzen/', 'design', $this->reset(...));
        $k->post(self::BASE . 'schriften/', 'design', $this->uploadFont(...));
        $k->post(self::BASE . 'schriften/{id:\d+}/loeschen/', 'design', $this->deleteFont(...));
    }

    /** @param array<string,string> $errors @param array<string,mixed>|null $raw */
    public function edit(Request $req, AdminContext $ctx, array $errors = [], ?array $raw = null, int $status = 200): Response
    {
        $design = $this->app->design();
        $values = $design->values();
        $defaults = DesignSchema::defaults();
        $fonts = $design->fonts();
        $display = [];
        foreach (DesignSchema::fields() as $f) {
            $display[$f['key']] = $raw !== null && array_key_exists($f['key'], $raw) && is_scalar($raw[$f['key']])
                ? (string) $raw[$f['key']] : self::show($values[$f['key']]);
        }
        $tabs = [];
        foreach (DesignSchema::TABS as $id => $tab) {
            $tabs[$id] = $tab + ['groups' => [], 'errors' => 0];
        }
        foreach (DesignSchema::fields() as $f) {
            if ($f['type'] === 'font') {
                $f['options'] = $fonts->options();
            }
            $f['default_label'] = self::defaultLabel($f, $fonts->options());
            $f['changed'] = !self::same($values[$f['key']], $defaults[$f['key']]);
            $tabs[$f['tab']]['groups'][$f['group']][] = $f;
            if (isset($errors[$f['key']])) {
                $tabs[$f['tab']]['errors']++;
            }
        }
        $exam = $this->app->db->value("SELECT slug FROM exam_types WHERE status = 'published' AND is_listed = 1 ORDER BY sort_order, id LIMIT 1");
        $pages = ['/' => 'Startseite', '/leistungen/' => 'Leistungen', '/telc-pruefungszentrum/' => 'Prüfungsübersicht'];
        if ($exam !== null) {
            $pages['/pruefungen/' . $exam . '/'] = 'Prüfungsseite';
        }
        $pages += ['/standorte/' => 'Standorte', '/kontakt/' => 'Kontaktformular', '/faq/' => 'Fragen und Antworten'];
        $meta = (array) ($this->app->settings->get('design_cache', []) ?? []);
        return Response::html($this->app->view->render('admin/design/edit.twig', [
            'title' => 'Designsystem', 'tabs' => $tabs, 'values' => $display, 'errors' => $errors,
            'warnings' => DesignValidator::warnings($values), 'pages' => $pages,
            'custom_fonts' => $this->app->db->all('SELECT * FROM design_fonts ORDER BY family, id'),
            'changed_count' => count($design->overrides()), 'published_at' => $meta['published_at'] ?? null,
            'stylesheet' => $design->stylesheetUrl(), 'max_font_mb' => 2, 'field_count' => count(DesignSchema::fields()),
        ]), $status);
    }

    public function save(Request $req, AdminContext $ctx): Response
    {
        $raw = $this->input($req);
        [$values, $errors] = $this->app->design()->validator()->validate($raw);
        if ($errors !== []) {
            return $this->edit($req, $ctx, $errors, $raw, 422);
        }
        $changes = $this->app->design()->save($values, $ctx->id());
        if ($changes !== []) {
            $this->app->audit->record('design.updated', 'Designsystem geändert (' . count($changes) . ' ' . (count($changes) === 1 ? 'Wert' : 'Werte') . ')', 'settings', null, ['changes' => $changes]);
            AdminKernel::flash('Designsystem gespeichert: ' . count($changes) . ' ' . (count($changes) === 1 ? 'Wert' : 'Werte') . ' geändert. Die Website zeigt die Änderungen ab sofort.');
        } else {
            AdminKernel::flash('Keine Änderungen – alles war schon so gespeichert.', 'info');
        }
        return Response::redirect(self::BASE . $this->tabFragment($req), 303);
    }

    /** Live-Vorschau: Werte prüfen und die CSS-Variablen berechnen, ohne zu speichern. */
    public function preview(Request $req, AdminContext $ctx): Response
    {
        $design = $this->app->design();
        $raw = $this->input($req);
        [$values, $errors] = $design->validator()->validate($raw);
        // Ungültige Felder zeigt die Vorschau mit dem gespeicherten Wert
        $saved = $design->values();
        foreach (array_keys($errors) as $key) {
            $values[$key] = $saved[$key];
        }
        return Response::json([
            'ok' => $errors === [], 'vars' => $design->css()->tokens($values), 'errors' => $errors,
            'warnings' => DesignValidator::warnings($values),
        ]);
    }

    public function reset(Request $req, AdminContext $ctx): Response
    {
        $before = $this->app->design()->overrides();
        $n = $this->app->design()->reset();
        $this->app->audit->record('design.reset', 'Designsystem auf Standardwerte zurückgesetzt', 'settings', null, ['vorher' => $before]);
        AdminKernel::flash($n > 0 ? "Alle $n geänderten Werte sind wieder auf Standard. Die Website nutzt die Standardwerte." : 'Es waren bereits alle Werte auf Standard.');
        return Response::redirect(self::BASE, 303);
    }

    public function uploadFont(Request $req, AdminContext $ctx): Response
    {
        $file = $req->files['font'] ?? null;
        $tmp = is_array($file) && is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $isTesting = $this->app->config->get('APP_ENV') === 'testing';
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !($isTesting ? is_file($tmp) : is_uploaded_file($tmp))) {
            $err = (int) (is_array($file) ? ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE);
            AdminKernel::flash(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Die Datei ist zu groß.' : 'Bitte eine Schriftdatei (WOFF2 oder WOFF) auswählen.', 'error');
            return Response::redirect(self::BASE . '#schriften', 303);
        }
        [$id, $errors] = $this->app->design()->addFont($tmp, (string) ($file['name'] ?? ''), $req->input('family'),
            (int) $req->input('weight_min', '400'), (int) $req->input('weight_max', '400'), $req->input('style', 'normal'), $req->input('fallback', 'sans'), $this->app->fontDir());
        if ($errors !== []) {
            AdminKernel::flash(implode(' ', $errors), 'error');
            return Response::redirect(self::BASE . '#schriften', 303);
        }
        $this->app->audit->record('design.font_added', 'Eigene Schrift hochgeladen: ' . $req->input('family'), 'design_font', $id);
        AdminKernel::flash('Schrift „' . $req->input('family') . '“ hochgeladen. Sie steht jetzt bei den Schriftfamilien zur Auswahl.');
        return Response::redirect(self::BASE . '#schriften', 303);
    }

    public function deleteFont(Request $req, AdminContext $ctx, string $id): Response
    {
        $row = $this->app->db->one('SELECT family FROM design_fonts WHERE id = ?', [(int) $id]);
        $errors = $this->app->design()->deleteFont((int) $id, $this->app->fontDir());
        if ($errors !== []) {
            AdminKernel::flash(implode(' ', $errors), 'error');
        } else {
            $this->app->audit->record('design.font_deleted', 'Eigene Schrift gelöscht: ' . ($row['family'] ?? $id), 'design_font', (int) $id);
            AdminKernel::flash('Schrift gelöscht.');
        }
        return Response::redirect(self::BASE . '#schriften', 303);
    }

    /** Nur die Felder des Designsystems aus dem Formular lesen; alles andere wird ignoriert. @return array<string,mixed> */
    private function input(Request $req): array
    {
        $raw = [];
        foreach (DesignSchema::fields() as $f) {
            if (array_key_exists($f['key'], $req->post)) {
                $raw[$f['key']] = $req->post[$f['key']];
            }
        }
        return $raw;
    }

    private function tabFragment(Request $req): string
    {
        $tab = $req->input('_tab');
        return isset(DesignSchema::TABS[$tab]) ? '#reiter-' . $tab : '';
    }

    private static function show(mixed $v): string
    {
        return is_float($v) ? \Mea\Module\Design\DesignCss::fmt($v) : (string) $v;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.00001 : (string) $a === (string) $b;
    }

    /** @param array<string,mixed> $f @param array<string,string> $fontOptions */
    private static function defaultLabel(array $f, array $fontOptions): string
    {
        $d = $f['default'];
        return match ($f['type']) {
            'font' => $fontOptions[$d] ?? (string) $d,
            'choice', 'weight' => (string) ($f['options'][$d] ?? $d),
            'px', 'number', 'em' => DesignValidator::num($d) . (isset($f['suffix']) ? ' ' . $f['suffix'] : (isset($f['unit']) && $f['type'] !== 'number' ? ' ' . $f['unit'] : '')),
            default => (string) $d,
        };
    }
}
