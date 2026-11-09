<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;

/** Termin absagen, Teilnehmerliste exportieren, Kalender (Monat, Woche, Tag). */
final class SessionAdminController
{
    private const TZ = 'Europe/Berlin';

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/termine/{id:\d+}/absagen/', 'sessions', $this->cancelForm(...));
        $k->post('/verwaltung/termine/{id:\d+}/absagen/', 'sessions', $this->cancel(...));
        $k->get('/verwaltung/termine/{id:\d+}/teilnehmer.csv', 'sessions', $this->csv(...));
        $k->get('/verwaltung/kalender/', 'sessions', $this->calendar(...));
        $k->post('/verwaltung/feiertage/erzeugen/', 'sessions', $this->generateHolidays(...));
    }

    /** @return array<string,mixed> */
    private function session(string $id): array
    {
        $s = $this->app->db->one('SELECT s.*, e.public_name, l.name AS location FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id JOIN locations l ON l.id = s.location_id WHERE s.id = ?', [(int) $id]);
        if ($s === null) {
            throw HttpException::notFound();
        }
        return $s;
    }

    public function cancelForm(Request $req, AdminContext $ctx, string $id): Response
    {
        $s = $this->session($id);
        $bookings = $this->app->db->all("SELECT b.*, p.first_name, p.last_name FROM bookings b LEFT JOIN booking_participants p ON p.booking_id = b.id WHERE b.exam_session_id = ? AND b.status IN ('confirmed','reserved','pending_payment')", [(int) $id]);
        return Response::html($this->app->view->render('admin/sessions/cancel.twig', [
            'title' => 'Termin absagen', 's' => $s, 'bookings' => $bookings,
            'paid' => array_values(array_filter($bookings, fn($b) => $b['payment_status'] === 'paid')),
        ]));
    }

    public function cancel(Request $req, AdminContext $ctx, string $id): Response
    {
        $s = $this->session($id);
        if ($s['status'] === 'cancelled') {
            AdminKernel::flash('Dieser Termin ist bereits abgesagt.', 'error');
            return Response::redirect('/verwaltung/termine/' . (int) $id . '/', 303);
        }
        $reason = mb_substr(trim($req->input('reason')), 0, 500);
        if ($reason === '') {
            AdminKernel::flash('Bitte einen Grund für die Absage angeben.', 'error');
            return Response::redirect('/verwaltung/termine/' . (int) $id . '/absagen/', 303);
        }
        $db = $this->app->db;
        $db->run("UPDATE exam_sessions SET status = 'cancelled', version = version + 1 WHERE id = ?", [(int) $id]);
        // offene Reservierungen freigeben; bezahlte Buchungen bleiben bestehen, bis über die Erstattung entschieden ist
        $db->run("UPDATE bookings SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancel_reason = ? WHERE exam_session_id = ? AND status IN ('reserved','pending_payment') AND payment_status <> 'paid'", ['Termin abgesagt', (int) $id]);
        $this->app->audit->record('exam_session.cancelled', 'Termin abgesagt: ' . $s['public_name'], 'exam_session', (int) $id, ['grund' => $reason]);
        $n = 0;
        if ($req->input('notify') === '1') {
            $n = $this->app->bookings()->notifySession((int) $id, 'session_cancelled', $reason);
        }
        $paid = (int) $db->value("SELECT COUNT(*) FROM bookings WHERE exam_session_id = ? AND status = 'confirmed' AND payment_status = 'paid'", [(int) $id]);
        AdminKernel::flash('Termin abgesagt.' . ($n ? " $n Teilnehmende werden per E-Mail informiert." : '') . ($paid ? " $paid bezahlte Buchungen: bitte unter „Buchungen“ stornieren und erstatten oder umbuchen." : ''), $paid ? 'warning' : 'success');
        return Response::redirect('/verwaltung/termine/' . (int) $id . '/', 303);
    }

    public function csv(Request $req, AdminContext $ctx, string $id): Response
    {
        $s = $this->session($id);
        $rows = $this->app->db->all(
            "SELECT b.public_ref, b.status, b.payment_status, p.salutation, p.first_name, p.last_name, p.birth_date, p.birth_place, p.email, p.phone, p.street, p.postal_code, p.city, p.extra_json
             FROM bookings b JOIN booking_participants p ON p.booking_id = b.id
             WHERE b.exam_session_id = ? AND b.status = 'confirmed' ORDER BY p.last_name, p.first_name", [(int) $id]);
        $extraKeys = [];
        foreach ($rows as $r) {
            foreach ((array) json_decode((string) $r['extra_json'], true) as $k => $v) {
                $extraKeys[$k] = $v['label'] ?? $k;
            }
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['Nummer', 'Anrede', 'Vorname', 'Nachname', 'Geburtsdatum', 'Geburtsort', 'E-Mail', 'Telefon', 'Straße', 'PLZ', 'Ort', 'Zahlung'], array_values($extraKeys)), ';');
        foreach ($rows as $r) {
            $extra = (array) json_decode((string) $r['extra_json'], true);
            $line = [$r['public_ref'], $r['salutation'], $r['first_name'], $r['last_name'], $r['birth_date'] ? date('d.m.Y', (int) strtotime($r['birth_date'])) : '', $r['birth_place'], $r['email'], $r['phone'], $r['street'], $r['postal_code'], $r['city'], $r['payment_status']];
            foreach (array_keys($extraKeys) as $k) {
                $v = $extra[$k]['value'] ?? '';
                $line[] = is_bool($v) ? ($v ? 'ja' : 'nein') : (string) $v;
            }
            // Formel-Injektion in Tabellenprogrammen verhindern
            fputcsv($out, array_map(fn($c) => preg_match('/^[=+\-@\t\r]/', (string) $c) ? "'" . $c : (string) $c, $line), ';');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        $this->app->audit->record('exam_session.export', 'Teilnehmerliste exportiert: ' . $s['public_name'], 'exam_session', (int) $id, ['anzahl' => count($rows)]);
        $name = 'teilnehmer-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($s['public_name'])) . '-' . substr($s['starts_at'], 0, 10) . '.csv';
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"']);
    }

    public function generateHolidays(Request $req, AdminContext $ctx): Response
    {
        $year = (int) $req->input('jahr');
        if ($year < 2020 || $year > 2100) {
            AdminKernel::flash('Bitte ein gültiges Jahr angeben.', 'error');
            return Response::redirect('/verwaltung/feiertage/', 303);
        }
        $n = 0;
        foreach (\Mea\Admin\Resources\HolidayResource::nrw($year) as $date => $name) {
            if ($this->app->db->value("SELECT 1 FROM holidays WHERE holiday_date = ? AND region = 'NW'", [$date]) === null) {
                $this->app->db->insert('holidays', ['holiday_date' => $date, 'name' => $name, 'region' => 'NW']);
                $n++;
            }
        }
        $this->app->audit->record('holiday.generated', "Feiertage NRW $year erzeugt ($n neu)", 'holiday', null);
        AdminKernel::flash("$n Feiertage für $year angelegt.");
        return Response::redirect('/verwaltung/feiertage/', 303);
    }

    public function calendar(Request $req, AdminContext $ctx): Response
    {
        $tz = new \DateTimeZone(self::TZ);
        $view = in_array($req->input('ansicht'), ['monat', 'woche', 'tag'], true) ? $req->input('ansicht') : 'monat';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $req->input('datum'), $tz) ?: new \DateTimeImmutable('today', $tz);
        [$from, $to, $prev, $next, $label] = match ($view) {
            'monat' => [
                $date->modify('first day of this month')->modify('monday this week'),
                $date->modify('last day of this month')->modify('sunday this week')->modify('+1 day'),
                $date->modify('first day of previous month'), $date->modify('first day of next month'),
                \IntlDateFormatter::formatObject($date, 'LLLL yyyy', 'de_DE'),
            ],
            'woche' => [
                $date->modify('monday this week'), $date->modify('monday this week')->modify('+7 days'),
                $date->modify('-7 days'), $date->modify('+7 days'),
                'KW ' . $date->format('W') . ' · ' . $date->modify('monday this week')->format('d.m.') . '–' . $date->modify('sunday this week')->format('d.m.Y'),
            ],
            default => [
                $date, $date->modify('+1 day'), $date->modify('-1 day'), $date->modify('+1 day'),
                \IntlDateFormatter::formatObject($date, 'EEEE, d. MMMM yyyy', 'de_DE'),
            ],
        };
        $utc = new \DateTimeZone('UTC');
        $filter = ctype_digit($req->input('ort')) ? (int) $req->input('ort') : null;
        $sessions = $this->app->db->all(
            "SELECT s.*, e.public_name, e.level_label, l.short_name AS location,
                (SELECT COUNT(*) FROM bookings b WHERE b.exam_session_id = s.id AND (b.status = 'confirmed' OR (b.status IN ('reserved','pending_payment') AND b.reserved_until > UTC_TIMESTAMP()))) AS taken
             FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id JOIN locations l ON l.id = s.location_id
             WHERE s.starts_at >= ? AND s.starts_at < ?" . ($filter ? ' AND s.location_id = ' . $filter : '') . ' ORDER BY s.starts_at',
            [$from->setTimezone($utc)->format('Y-m-d H:i:s'), $to->setTimezone($utc)->format('Y-m-d H:i:s')],
        );
        $holidays = array_column($this->app->db->all('SELECT holiday_date, name FROM holidays WHERE holiday_date >= ? AND holiday_date < ?', [$from->format('Y-m-d'), $to->format('Y-m-d')]), 'name', 'holiday_date');
        $byDay = [];
        foreach ($sessions as $s) {
            $local = (new \DateTimeImmutable((string) $s['starts_at'], $utc))->setTimezone($tz);
            $s['local'] = $local;
            $s['full'] = (int) $s['taken'] >= (int) $s['capacity'];
            $byDay[$local->format('Y-m-d')][] = $s;
        }
        $days = [];
        for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $days[] = ['date' => $d, 'key' => $key, 'other' => $view === 'monat' && $d->format('m') !== $date->format('m'),
                'today' => $key === (new \DateTimeImmutable('today', $tz))->format('Y-m-d'), 'holiday' => $holidays[$key] ?? null, 'sessions' => $byDay[$key] ?? []];
        }
        return Response::html($this->app->view->render('admin/sessions/calendar.twig', [
            'title' => 'Kalender', 'view' => $view, 'date' => $date, 'days' => $days, 'label' => $label,
            'prev' => $prev->format('Y-m-d'), 'next' => $next->format('Y-m-d'), 'today' => (new \DateTimeImmutable('today', $tz))->format('Y-m-d'),
            'locations' => $this->app->db->all('SELECT id, short_name FROM locations ORDER BY sort_order'), 'location_filter' => $filter,
        ]));
    }
}
