<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Mail\TemplateRenderer;

/** E-Mail-Protokoll (Warteschlange) und Probeversand von Vorlagen. */
final class EmailLogController
{
    private const PER_PAGE = 50;

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/email-protokoll/', 'emails', $this->index(...));
        $k->get('/verwaltung/email-protokoll/{id:\d+}/', 'emails', $this->show(...));
        $k->get('/verwaltung/email-protokoll/{id:\d+}/inhalt/', 'emails', $this->body(...));
        $k->post('/verwaltung/email-protokoll/{id:\d+}/erneut/', 'emails', $this->retry(...));
        $k->post('/verwaltung/email-protokoll/senden/', 'emails', $this->process(...));
        $k->get('/verwaltung/email-vorlagen/{id:\d+}/beispiel/', 'emails', $this->templatePreview(...));
        $k->post('/verwaltung/email-vorlagen/{id:\d+}/testen/', 'emails', $this->templateTest(...));
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $where = [];
        $params = [];
        $query = [];
        $status = $req->input('status');
        if (isset(Labels::MAPS['email_status'][$status])) {
            $where[] = 'status = ?';
            $params[] = $status;
            $query['status'] = $status;
        }
        $q = mb_substr($req->input('q'), 0, 100);
        if ($q !== '') {
            $where[] = '(to_email LIKE ? OR subject LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
            $query['q'] = $q;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $this->app->db->value('SELECT COUNT(*) FROM email_outbox' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $req->input('seite')));
        $rows = $this->app->db->all(
            'SELECT id, template_key, to_email, subject, status, attempts, last_error, created_at, sent_at, next_attempt_at FROM email_outbox'
            . $sqlWhere . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        $counts = [];
        foreach ($this->app->db->all('SELECT status, COUNT(*) AS n FROM email_outbox GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return Response::html($this->app->view->render('admin/emails/log.twig', [
            'title' => 'E-Mail-Protokoll', 'rows' => $rows, 'query' => $query, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'counts' => $counts, 'configured' => $this->app->outbox()->isConfigured(),
        ]));
    }

    public function show(Request $req, AdminContext $ctx, string $id): Response
    {
        $m = $this->app->db->one('SELECT * FROM email_outbox WHERE id = ?', [(int) $id]);
        if ($m === null) {
            throw HttpException::notFound();
        }
        return Response::html($this->app->view->render('admin/emails/show.twig', ['title' => 'E-Mail ' . $m['id'], 'm' => $m]));
    }

    /** HTML der Nachricht, nur in einem abgeschotteten Rahmen der Verwaltung anzeigbar. */
    public function body(Request $req, AdminContext $ctx, string $id): Response
    {
        $html = $this->app->db->value('SELECT body_html FROM email_outbox WHERE id = ?', [(int) $id]);
        if ($html === null) {
            throw HttpException::notFound();
        }
        return self::framed((string) $html);
    }

    public function retry(Request $req, AdminContext $ctx, string $id): Response
    {
        $this->app->outbox()->retry((int) $id);
        $this->app->audit->record('email.retry', 'E-Mail erneut eingereiht', 'email', (int) $id);
        AdminKernel::flash('Die E-Mail wird erneut versendet.');
        return Response::redirect('/verwaltung/email-protokoll/' . (int) $id . '/', 303);
    }

    /** Warteschlange sofort abarbeiten (sonst erledigt das der Cron-Job). */
    public function process(Request $req, AdminContext $ctx): Response
    {
        $w = $this->app->outbox();
        if (!$w->isConfigured()) {
            AdminKernel::flash('Der E-Mail-Versand ist noch nicht eingerichtet (MAIL_DSN und MAIL_FROM in der .env).', 'error');
        } else {
            $r = $w->run(50);
            AdminKernel::flash("Versand ausgeführt: {$r['sent']} gesendet, {$r['failed']} fehlgeschlagen.", $r['failed'] > 0 ? 'error' : 'success');
        }
        return Response::redirect('/verwaltung/email-protokoll/', 303);
    }

    public function templatePreview(Request $req, AdminContext $ctx, string $id): Response
    {
        $t = $this->template((int) $id);
        $msg = (new TemplateRenderer($this->app->db, $this->app->root))->renderRaw((string) $t['subject'], (string) $t['body_html'], self::sample() + $this->app->mailer->commonVars());
        return self::framed($msg['html']);
    }

    /** Probe an die eigene Adresse der angemeldeten Person, mit Beispielwerten. */
    public function templateTest(Request $req, AdminContext $ctx, string $id): Response
    {
        $t = $this->template((int) $id);
        $back = '/verwaltung/email-vorlagen/' . (int) $id . '/';
        $key = 'test-' . $t['template_key'] . '-' . $ctx->id() . '-' . bin2hex(random_bytes(4));
        $this->app->mailer->queue((string) $t['template_key'], (string) $ctx->user['email'], (string) $ctx->user['name'], self::sample(), $key, 'test', (int) $id);
        $w = $this->app->outbox();
        if (!$w->isConfigured()) {
            AdminKernel::flash('Die Probe liegt in der Warteschlange. Versendet wird sie, sobald der E-Mail-Versand eingerichtet ist.', 'error');
            return Response::redirect($back, 303);
        }
        $w->run(10);
        $status = $this->app->db->value('SELECT status FROM email_outbox WHERE idempotency_key = ?', [$key]);
        AdminKernel::flash($status === 'sent' ? 'Probe an ' . $ctx->user['email'] . ' gesendet.' : 'Die Probe konnte nicht gesendet werden. Details stehen im E-Mail-Protokoll.', $status === 'sent' ? 'success' : 'error');
        return Response::redirect($back, 303);
    }

    /** @return array<string,mixed> */
    private function template(int $id): array
    {
        $t = $this->app->db->one('SELECT * FROM email_templates WHERE id = ?', [$id]);
        if ($t === null) {
            throw HttpException::notFound();
        }
        return $t;
    }

    /** Beispielwerte für Vorschau und Probeversand. Deutlich als Beispiel erkennbar. @return array<string,mixed> */
    public static function sample(): array
    {
        return [
            'name' => 'Beispiel Person', 'rolle' => 'Redaktion', 'link' => 'https://example.org/beispiel-link', 'gueltig_stunden' => 48, 'gueltig_minuten' => 60,
            'hinweis' => 'Dies ist ein Beispieltext für die Vorschau.', 'bezahlt' => true,
            'buchung' => ['nummer' => 'MEA-BEISPIEL', 'betrag' => '0,00 €', 'status' => 'confirmed'],
            'teilnehmer' => ['vorname' => 'Beispiel', 'nachname' => 'Person', 'name' => 'Beispiel Person', 'email' => 'beispiel@example.org'],
            'pruefung' => ['name' => 'Beispielprüfung', 'niveau' => 'B1', 'anbieter' => 'telc'],
            'termin' => ['datum' => '01.01.2030', 'uhrzeit' => '09:00', 'ort' => 'Beispielstandort, Musterstraße 1, 12345 Musterstadt', 'hinweise' => 'Beispielhinweis zum Termin'],
            'preis' => ['positionen' => [['bezeichnung' => 'Prüfungsgebühr', 'betrag' => '0,00 €']]],
            'anfrage' => ['name' => 'Beispiel Person', 'email' => 'beispiel@example.org', 'telefon' => '', 'thema' => 'Prüfung / telc', 'standort' => '', 'nachricht' => 'Beispielnachricht'],
        ];
    }

    /** Antwort, die nur im Rahmen der Verwaltung angezeigt wird und keine Skripte ausführen darf. */
    private static function framed(string $html): Response
    {
        return Response::html($html)
            ->withHeader('Content-Security-Policy', "default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox")
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
