<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Admin\Crud\Form;
use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Firmendaten, Startseite, SEO, Benachrichtigungen und Datenschutzfristen (Tabelle settings). */
final class SettingsController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/einstellungen/', 'settings', $this->edit(...));
        $k->post('/verwaltung/einstellungen/', 'settings', $this->save(...));
    }

    /** @return list<array<string,mixed>> Feldname = Einstellung__Schlüssel */
    private function fields(): array
    {
        return [
            ['name' => 'company__name', 'label' => 'Name', 'required' => true, 'max' => 120, 'group' => 'Firmendaten'],
            ['name' => 'company__legal_name', 'label' => 'Firmierung', 'required' => true, 'max' => 150, 'group' => 'Firmendaten'],
            ['name' => 'company__claim', 'label' => 'Leitsatz', 'max' => 150, 'group' => 'Firmendaten'],
            ['name' => 'company__email', 'label' => 'E-Mail-Adresse', 'type' => 'email', 'required' => true, 'group' => 'Firmendaten', 'help' => 'Erscheint nicht im Klartext auf der Website.'],
            ['name' => 'company__phone', 'label' => 'Telefon (international)', 'type' => 'tel', 'required' => true, 'max' => 40, 'group' => 'Firmendaten', 'help' => 'z. B. +49 2365 6973491 – für Links'],
            ['name' => 'company__phone_display', 'label' => 'Telefon (Anzeige)', 'required' => true, 'max' => 40, 'group' => 'Firmendaten'],
            ['name' => 'company__whatsapp', 'label' => 'WhatsApp (international)', 'type' => 'tel', 'max' => 40, 'group' => 'Firmendaten'],
            ['name' => 'company__whatsapp_display', 'label' => 'WhatsApp (Anzeige)', 'max' => 40, 'group' => 'Firmendaten'],
            ['name' => 'company__facebook', 'label' => 'Facebook-Seite', 'type' => 'url', 'max' => 255, 'group' => 'Firmendaten'],
            ['name' => 'company__instagram', 'label' => 'Instagram-Profil', 'type' => 'url', 'max' => 255, 'group' => 'Firmendaten'],
            ['name' => 'company__logo_media_id', 'label' => 'Logo', 'type' => 'media', 'group' => 'Firmendaten'],



            ['name' => 'consent__ga_id', 'label' => 'Google Analytics: Mess-ID', 'max' => 20, 'group' => 'Cookies und Dienste',
                'help' => 'Format G-XXXXXXX. Leer = Google Analytics wird nicht verwendet. Geladen wird es nur nach Einwilligung „Statistik“. Datenschutzerklärung anpassen!'],
            ['name' => 'consent__meta_pixel_id', 'label' => 'Meta-Pixel: Pixel-ID', 'max' => 20, 'group' => 'Cookies und Dienste',
                'help' => 'Nur Ziffern. Leer = kein Meta-Pixel. Geladen nur nach Einwilligung „Marketing“. Datenschutzerklärung anpassen!'],
            ['name' => 'consent__youtube', 'label' => 'YouTube-Videos werden eingebunden', 'type' => 'checkbox', 'group' => 'Cookies und Dienste'],
            ['name' => 'consent__revision', 'label' => 'Version der Cookie-Hinweise', 'type' => 'int', 'min' => 1, 'maxValue' => 999, 'default' => 1, 'group' => 'Cookies und Dienste',
                'help' => 'Um 1 erhöhen, wenn alle Besucher erneut gefragt werden sollen (z. B. nach Änderung der Datenschutzerklärung).'],

            ['name' => 'notifications__office_email', 'label' => 'E-Mail für interne Benachrichtigungen', 'type' => 'email', 'group' => 'Benachrichtigungen',
                'help' => 'Neue Buchungen und Kontaktanfragen. Leer = Firmen-E-Mail.'],

            ['name' => 'retention__inquiries_months', 'label' => 'Kontaktanfragen anonymisieren nach (Monaten)', 'type' => 'int', 'min' => 1, 'maxValue' => 120, 'default' => 12, 'group' => 'Datenschutzfristen'],
            ['name' => 'retention__unpaid_bookings_days', 'label' => 'Abgebrochene, unbezahlte Anmeldungen löschen nach (Tagen)', 'type' => 'int', 'min' => 1, 'maxValue' => 365, 'default' => 30, 'group' => 'Datenschutzfristen'],
            ['name' => 'retention__bookings_years', 'label' => 'Bezahlte Buchungen aufbewahren (Jahre)', 'type' => 'int', 'min' => 6, 'maxValue' => 15, 'default' => 10, 'group' => 'Datenschutzfristen',
                'help' => 'Buchungs- und Zahlungsbelege unterliegen gesetzlichen Aufbewahrungspflichten (in der Regel 6 bzw. 10 Jahre). Bitte mit der Steuerberatung abstimmen.'],
            ['name' => 'retention__email_log_days', 'label' => 'E-Mail-Protokoll löschen nach (Tagen)', 'type' => 'int', 'min' => 30, 'maxValue' => 3650, 'default' => 180, 'group' => 'Datenschutzfristen'],
            ['name' => 'reminders__days_before', 'label' => 'Terminerinnerung an Teilnehmende (Tage vorher)', 'type' => 'int', 'min' => 1, 'maxValue' => 30, 'default' => 3, 'group' => 'Automatik'],
            ['name' => 'backup__keep_days', 'label' => 'Nächtliche Sicherungen aufbewahren (Tage)', 'type' => 'int', 'min' => 3, 'maxValue' => 365, 'default' => 14, 'group' => 'Automatik',
                'help' => 'Die drei neuesten Sicherungen bleiben immer erhalten. Sicherungen liegen auf demselben Server; bitte regelmäßig herunterladen.'],
            ['name' => 'backup__keep_manual_days', 'label' => 'Manuelle Sicherungen aufbewahren (Tage)', 'type' => 'int', 'min' => 7, 'maxValue' => 3650, 'default' => 90, 'group' => 'Automatik'],
            ['name' => 'retention__audit_years', 'label' => 'Änderungsprotokoll aufbewahren (Jahre)', 'type' => 'int', 'min' => 1, 'maxValue' => 10, 'default' => 3, 'group' => 'Datenschutzfristen'],
        ];
    }

    /** @return array<string,mixed> */
    private function current(): array
    {
        $row = [];
        foreach ($this->fields() as $f) {
            [$key, $sub] = explode('__', (string) $f['name'], 2);
            $v = ($this->app->settings->get($key, []) ?? [])[$sub] ?? null;
            if (($f['type'] ?? '') === 'lines' && is_array($v)) {
                $v = json_encode(array_map(fn($x) => is_array($x) ? implode(' | ', array_map('strval', $x)) : (string) $x, $v), JSON_UNESCAPED_UNICODE);
            }
            $row[$f['name']] = $v;
        }
        return $row;
    }

    public function edit(Request $req, AdminContext $ctx, array $errors = [], ?array $values = null, int $status = 200): Response
    {
        $groups = [];
        foreach ($this->fields() as $f) {
            $groups[$f['group']][] = $f;
        }
        $media = $this->app->db->all("SELECT m.id, m.original_name, m.alt_text, (SELECT path FROM media_variants v WHERE v.media_id = m.id ORDER BY v.width LIMIT 1) AS thumb FROM media m WHERE m.kind = 'image' ORDER BY m.id DESC");
        return Response::html($this->app->view->render('admin/settings/edit.twig', [
            'title' => 'Firmendaten & SEO', 'groups' => $groups, 'values' => $values ?? Form::display($this->fields(), $this->current()),
            'errors' => $errors, 'media_options' => $media,
        ]), $status);
    }

    public function save(Request $req, AdminContext $ctx): Response
    {
        [$values, $errors, $raw] = Form::read($req, $this->fields(), $this->app->html);
        $structured = [];
        foreach ($this->fields() as $f) {
            [$key, $sub] = explode('__', (string) $f['name'], 2);
            $v = $values[$f['name']] ?? null;
            if (($f['type'] ?? '') === 'lines') {
                $parts = array_map(fn($l) => array_map('trim', explode('|', (string) $l)), (array) json_decode((string) ($v ?? '[]'), true));
                $v = match ($sub) {
                    'partners' => array_map(fn($p) => ['media_id' => (int) $p[0], 'label' => $p[1] ?? ''], $parts),
                    default => array_map(fn($p) => count($p) >= 3 ? ['icon' => $p[0], 'title' => $p[1], 'text' => implode(' | ', array_slice($p, 2))] : ['icon' => null, 'title' => $p[0], 'text' => $p[1] ?? ''], $parts),
                };
                if (in_array($sub, ['benefits', 'why'], true)) {
                    foreach ($parts as $i => $p) {
                        if (count($p) < 3) {
                            $errors[$f['name']] = 'Zeile ' . ($i + 1) . ': bitte „Symbol | Titel | Text“ angeben.';
                        }
                    }
                }
                if ($sub === 'partners') {
                    foreach ($v as $i => $p) {
                        if ($this->app->db->value('SELECT 1 FROM media WHERE id = ?', [$p['media_id']]) === null) {
                            $errors[$f['name']] = 'Zeile ' . ($i + 1) . ': Medien-Nr. ' . $p['media_id'] . ' gibt es nicht.';
                        }
                    }
                }
            }
            $structured[$key][$sub] = $v;
        }
        $ga = strtoupper(trim((string) ($structured['consent']['ga_id'] ?? '')));
        if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $ga)) {
            $errors['consent__ga_id'] = 'Bitte die Mess-ID im Format G-XXXXXXX eintragen oder leer lassen.';
        }
        $px = trim((string) ($structured['consent']['meta_pixel_id'] ?? ''));
        if ($px !== '' && !preg_match('/^\d{8,20}$/', $px)) {
            $errors['consent__meta_pixel_id'] = 'Die Pixel-ID besteht nur aus Ziffern (8 bis 20).';
        }
        if ($errors !== []) {
            return $this->edit($req, $ctx, $errors, array_merge(Form::display($this->fields(), $this->current()), array_map(fn($x) => is_array($x) ? $x : (string) $x, $raw)), 422);
        }
        $before = [];
        foreach ($structured as $key => $data) {
            $old = $this->app->settings->get($key, []) ?? [];
            $before[$key] = $old;
            $this->app->settings->set($key, array_merge(is_array($old) ? $old : [], $data));
        }
        $changes = [];
        foreach ($structured as $key => $data) {
            foreach ($data as $sub => $v) {
                if (json_encode($before[$key][$sub] ?? null) !== json_encode($v)) {
                    $changes["$key.$sub"] = [$before[$key][$sub] ?? null, $v];
                }
            }
        }
        $this->app->audit->record('settings.updated', 'Einstellungen geändert', 'settings', null, ['changes' => $changes]);
        AdminKernel::flash('Einstellungen gespeichert.');
        return Response::redirect('/verwaltung/einstellungen/', 303);
    }
}
