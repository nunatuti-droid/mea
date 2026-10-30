<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Admin\Crud\Form;
use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Bookings\BookingException;

/** Buchungen: Liste, Details, Stornieren, Erstatten, Bestätigung erneut senden, Export. */
final class BookingAdminController
{
    private const PER_PAGE = 30;

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/buchungen/', 'bookings', $this->index(...));
        $k->get('/verwaltung/buchungen/export.csv', 'bookings', $this->export(...));
        $k->get('/verwaltung/buchungen/{id:\d+}/', 'bookings', $this->show(...));
        $k->post('/verwaltung/buchungen/{id:\d+}/stornieren/', 'bookings', $this->cancel(...));
        $k->post('/verwaltung/buchungen/{id:\d+}/erstatten/', 'refunds', $this->refund(...));
        $k->post('/verwaltung/buchungen/{id:\d+}/bestaetigung/', 'bookings', $this->resend(...));
        $k->post('/verwaltung/buchungen/{id:\d+}/notiz/', 'bookings', $this->note(...));
        $k->post('/verwaltung/buchungen/{id:\d+}/zahlung-pruefen/', 'bookings', $this->checkPayment(...));
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function where(Request $req): array
    {
        $w = ['1=1'];
        $p = [];
        if (($q = mb_substr($req->input('q'), 0, 100)) !== '') {
            $w[] = '(b.public_ref LIKE ? OR p.last_name LIKE ? OR p.first_name LIKE ? OR p.email LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like, $like, $like);
        }
        foreach (['status' => 'b.status', 'zahlung' => 'b.payment_status'] as $in => $col) {
            if ($req->input($in) !== '' && preg_match('/^[a-z_]+$/', $req->input($in))) {
                $w[] = "$col = ?";
                $p[] = $req->input($in);
            }
        }
        if (ctype_digit($req->input('pruefung'))) {
            $w[] = 'b.exam_type_id = ?';
            $p[] = (int) $req->input('pruefung');
        }
        if (ctype_digit($req->input('termin'))) {
            $w[] = 'b.exam_session_id = ?';
            $p[] = (int) $req->input('termin');
        }
        return [implode(' AND ', $w), $p];
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        [$where, $params] = $this->where($req);
        $base = "FROM bookings b LEFT JOIN booking_participants p ON p.booking_id = b.id JOIN exam_sessions s ON s.id = b.exam_session_id JOIN exam_types e ON e.id = b.exam_type_id WHERE $where";
        $total = (int) $this->app->db->value("SELECT COUNT(*) $base", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $req->input('seite', '1')), $pages);
        $rows = $this->app->db->all("SELECT b.*, p.first_name, p.last_name, p.email, s.starts_at, JSON_UNQUOTE(JSON_EXTRACT(b.snapshot_json, '$.exam.public_name')) AS exam_name $base ORDER BY b.created_at DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $query = array_filter(['q' => $req->input('q'), 'status' => $req->input('status'), 'zahlung' => $req->input('zahlung'), 'pruefung' => $req->input('pruefung'), 'termin' => $req->input('termin')], fn($v) => $v !== '');
        return Response::html($this->app->view->render('admin/bookings/index.twig', [
            'title' => 'Buchungen', 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'query' => $query,
            'exams' => $this->app->db->all('SELECT id, public_name FROM exam_types ORDER BY sort_order, public_name'),
        ]));
    }

    /** @return array<string,mixed> */
    private function booking(string $id): array
    {
        $b = $this->app->db->one('SELECT * FROM bookings WHERE id = ?', [(int) $id]);
        if ($b === null) {
            throw HttpException::notFound();
        }
        return $this->app->bookings()->hydrate($b);
    }

    public function show(Request $req, AdminContext $ctx, string $id): Response
    {
        $b = $this->booking($id);
        $paid = 0;
        $refunded = 0;
        foreach ($b['payments'] as $p) {
            if (in_array($p['status'], ['captured', 'partially_refunded', 'refunded'], true)) {
                $paid += (int) $p['amount_cents'];
                $refunded += (int) $p['refunded_cents'];
            }
        }
        $current = $this->app->db->one('SELECT s.*, l.name AS location FROM exam_sessions s JOIN locations l ON l.id = s.location_id WHERE s.id = ?', [$b['exam_session_id']]);
        return Response::html($this->app->view->render('admin/bookings/show.twig', [
            'title' => 'Buchung ' . $b['public_ref'], 'b' => $b, 'paid' => $paid, 'refunded' => $refunded, 'open_refund' => $paid - $refunded,
            'current_session' => $current,
            'emails' => $this->app->db->all("SELECT id, template_key, subject, status, created_at, sent_at FROM email_outbox WHERE related_type = 'booking' AND related_id = ? ORDER BY id", [(int) $id]),
            'history' => $this->app->db->all("SELECT a.created_at, a.summary, u.name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity_type = 'booking' AND a.entity_id = ? ORDER BY a.id", [(int) $id]),
            'extra' => (array) json_decode((string) ($b['participant']['extra_json'] ?? '[]'), true),
        ]));
    }

    public function cancel(Request $req, AdminContext $ctx, string $id): Response
    {
        $b = $this->booking($id);
        $reason = trim(mb_substr($req->input('reason'), 0, 255));
        if ($reason === '') {
            AdminKernel::flash('Bitte einen Grund angeben.', 'error');
            return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
        }
        $refund = $req->input('refund') === '1' && $ctx->can('refunds');
        $amount = $req->input('amount') !== '' ? Form::parseMoney($req->input('amount')) : null;
        try {
            $this->app->bookings()->cancelByAdmin((int) $id, $reason, $amount, $refund, $req->input('notify') === '1', $ctx->id());
            AdminKernel::flash('Buchung storniert.' . ($refund ? ' Die Erstattung wurde an PayPal übermittelt.' : ''));
        } catch (BookingException $e) {
            AdminKernel::flash($e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
    }

    public function refund(Request $req, AdminContext $ctx, string $id): Response
    {
        $this->booking($id);
        $amount = Form::parseMoney($req->input('amount'));
        $reason = trim(mb_substr($req->input('reason'), 0, 255));
        if ($amount === null || $reason === '') {
            AdminKernel::flash('Bitte Betrag und Grund angeben.', 'error');
            return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
        }
        try {
            $done = $this->app->bookings()->refund((int) $id, $amount, $reason, $ctx->id());
            if ($req->input('notify') === '1') {
                $this->app->bookings()->notifyCustomer((int) $id, 'booking_refunded', 'Wir haben ' . number_format($done / 100, 2, ',', '.') . ' € erstattet. Grund: ' . $reason, 'r' . time());
            }
            AdminKernel::flash('Erstattung über ' . number_format($done / 100, 2, ',', '.') . ' € ausgeführt.');
        } catch (BookingException $e) {
            AdminKernel::flash($e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
    }

    public function resend(Request $req, AdminContext $ctx, string $id): Response
    {
        $b = $this->booking($id);
        if ($b['status'] !== 'confirmed') {
            AdminKernel::flash('Nur bestätigte Buchungen haben eine Bestätigung.', 'error');
        } else {
            $this->app->bookings()->sendConfirmation((int) $id, true);
            $this->app->audit->record('booking.resent', 'Bestätigung erneut gesendet', 'booking', (int) $id);
            AdminKernel::flash('Die Bestätigung wird erneut gesendet.');
        }
        return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
    }

    public function note(Request $req, AdminContext $ctx, string $id): Response
    {
        $this->booking($id);
        $this->app->db->run('UPDATE bookings SET internal_note = ? WHERE id = ?', [mb_substr($req->input('internal_note'), 0, 5000), (int) $id]);
        $this->app->audit->record('booking.note', 'Interne Notiz geändert', 'booking', (int) $id);
        AdminKernel::flash('Notiz gespeichert.');
        return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
    }

    /** Zahlung bei PayPal nachfragen, falls Rückleitung und Webhook ausgeblieben sind. */
    public function checkPayment(Request $req, AdminContext $ctx, string $id): Response
    {
        $b = $this->booking($id);
        $p = end($b['payments']) ?: null;
        if ($p === null) {
            AdminKernel::flash('Zu dieser Buchung gibt es keinen Zahlungsvorgang.', 'error');
        } else {
            $result = $this->app->bookings()->completeReturn((string) $b['public_ref'], (string) $p['provider_order_id']);
            $this->app->audit->record('payment.checked', 'Zahlungsstatus bei PayPal abgefragt: ' . $result, 'booking', (int) $id);
            AdminKernel::flash(match ($result) {
                'confirmed' => 'Zahlung bestätigt.',
                'pending' => 'PayPal meldet die Zahlung noch als ausstehend.',
                'refunded_full' => 'Zahlung eingegangen, Termin voll: automatisch erstattet.',
                default => 'Keine abgeschlossene Zahlung gefunden.',
            }, $result === 'failed' ? 'error' : 'success');
        }
        return Response::redirect('/verwaltung/buchungen/' . (int) $id . '/', 303);
    }

    public function export(Request $req, AdminContext $ctx): Response
    {
        [$where, $params] = $this->where($req);
        $rows = $this->app->db->all("SELECT b.public_ref, b.created_at, b.status, b.payment_status, b.total_cents, b.currency, p.first_name, p.last_name, p.email, s.starts_at,
            JSON_UNQUOTE(JSON_EXTRACT(b.snapshot_json, '$.exam.public_name')) AS exam_name,
            (SELECT GROUP_CONCAT(provider_capture_id) FROM payments x WHERE x.booking_id = b.id AND provider_capture_id IS NOT NULL) AS transactions
            FROM bookings b LEFT JOIN booking_participants p ON p.booking_id = b.id JOIN exam_sessions s ON s.id = b.exam_session_id JOIN exam_types e ON e.id = b.exam_type_id
            WHERE $where ORDER BY b.created_at DESC LIMIT 10000", $params);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Nummer', 'Gebucht am', 'Prüfung', 'Termin', 'Vorname', 'Nachname', 'E-Mail', 'Status', 'Zahlung', 'Betrag', 'Transaktion'], ';');
        $tz = new \DateTimeZone('Europe/Berlin');
        foreach ($rows as $r) {
            $line = [$r['public_ref'], (new \DateTimeImmutable($r['created_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y H:i'), $r['exam_name'],
                (new \DateTimeImmutable($r['starts_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y H:i'),
                $r['first_name'], $r['last_name'], $r['email'], $r['status'], $r['payment_status'], number_format($r['total_cents'] / 100, 2, ',', ''), $r['transactions']];
            fputcsv($out, array_map(fn($c) => preg_match('/^[=+\-@\t\r]/', (string) $c) ? "'" . $c : (string) $c, $line), ';');
        }
        rewind($out);
        $this->app->audit->record('booking.export', 'Buchungen exportiert (' . count($rows) . ')', 'booking', null);
        return new Response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="buchungen-' . date('Y-m-d') . '.csv"']);
    }
}
