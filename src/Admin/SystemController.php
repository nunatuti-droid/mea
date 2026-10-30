<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Systemprüfung mit Probeversand einer E-Mail. */
final class SystemController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/system/', 'system', $this->index(...));
        $k->post('/verwaltung/system/mailtest/', 'system', $this->mailTest(...));
        $k->post('/verwaltung/system/dateien-pruefen/', 'system', $this->integrity(...));
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $groups = [];
        foreach ((new SystemCheck($this->app))->run() as $row) {
            $groups[$row['group']][] = $row;
        }
        return Response::html($this->app->view->render('admin/system.twig', ['title' => 'Systemprüfung', 'groups' => $groups]));
    }

    public function integrity(Request $req, AdminContext $ctx): Response
    {
        $res = (new \Mea\Module\Ops\Scheduler($this->app))->run(null, ['integrity'])['integrity'] ?? '';
        $run = $this->app->db->one('SELECT * FROM integrity_runs ORDER BY id DESC LIMIT 1');
        $diff = $run && $run['changed_json'] ? (array) json_decode((string) $run['changed_json'], true) : [];
        AdminKernel::flash('Dateiwächter: ' . $res . ($diff ? ' – ' . implode(', ', array_slice($diff, 0, 5)) : ''), str_starts_with($res, 'ok') ? 'success' : 'error');
        return Response::redirect('/verwaltung/system/', 303);
    }

    /** Einfache Nachricht ohne Vorlage direkt an die eigene Adresse senden. */
    public function mailTest(Request $req, AdminContext $ctx): Response
    {
        $w = $this->app->outbox();
        if (!$w->isConfigured()) {
            AdminKernel::flash('Der E-Mail-Versand ist nicht eingerichtet. Bitte MAIL_DSN und MAIL_FROM in der .env setzen.', 'error');
            return Response::redirect('/verwaltung/system/', 303);
        }
        $body = '<p>Diese Testnachricht wurde über die Systemprüfung der Verwaltung gesendet. Der E-Mail-Versand funktioniert.</p>';
        $id = $this->app->db->insert('email_outbox', [
            'idempotency_key' => 'systemtest-' . bin2hex(random_bytes(8)), 'template_key' => null,
            'to_email' => (string) $ctx->user['email'], 'to_name' => (string) $ctx->user['name'],
            'subject' => 'Testnachricht der Website-Verwaltung', 'body_html' => $body,
            'body_text' => \Mea\Module\Mail\TemplateRenderer::toText($body), 'status' => 'sending', 'attempts' => 1,
            'next_attempt_at' => gmdate('Y-m-d H:i:s'), 'locked_until' => gmdate('Y-m-d H:i:s', time() + 300), 'related_type' => 'test',
        ]);
        $ok = $w->sendOne($id);
        $this->app->audit->record('email.test', 'Testnachricht gesendet' . ($ok ? '' : ' (fehlgeschlagen)'), 'email', $id);
        AdminKernel::flash($ok ? 'Testnachricht an ' . $ctx->user['email'] . ' gesendet.' : 'Senden fehlgeschlagen. Die Fehlermeldung steht im E-Mail-Protokoll.', $ok ? 'success' : 'error');
        return Response::redirect('/verwaltung/system/', 303);
    }
}
