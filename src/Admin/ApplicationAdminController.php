<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Applications\ApplicationService;

/** Bewerbungen ansehen, Unterlagen herunterladen, Status setzen und löschen (nur mit Recht „Bewerbungen“). */
final class ApplicationAdminController
{
    public const STATUS_MAP = [
        'new' => ['label' => 'Neu', 'tone' => 'success'],
        'in_progress' => ['label' => 'In Bearbeitung', 'tone' => 'warning'],
        'closed' => ['label' => 'Abgeschlossen', 'tone' => 'neutral'],
    ];

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/bewerbungen/', 'applications', $this->index(...));
        $k->get('/verwaltung/bewerbungen/{id:\d+}/', 'applications', $this->show(...));
        $k->get('/verwaltung/bewerbungen/{id:\d+}/datei/{file:\d+}/', 'applications', $this->download(...));
        $k->post('/verwaltung/bewerbungen/{id:\d+}/status/', 'applications', $this->setStatus(...));
        $k->post('/verwaltung/bewerbungen/{id:\d+}/loeschen/', 'applications', $this->delete(...));
    }

    private function find(string $id): array
    {
        $a = $this->app->db->one('SELECT * FROM applications WHERE id = ?', [(int) $id]);
        if ($a === null) {
            throw HttpException::notFound();
        }
        return $a;
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $status = array_key_exists($req->input('status'), self::STATUS_MAP) ? $req->input('status') : '';
        $rows = $this->app->db->all('SELECT a.*, (SELECT COUNT(*) FROM application_files f WHERE f.application_id = a.id) AS files
            FROM applications a' . ($status !== '' ? ' WHERE a.status = ?' : '') . ' ORDER BY a.id DESC LIMIT 300', $status !== '' ? [$status] : []);
        return Response::html($this->app->view->render('admin/applications/index.twig', [
            'title' => 'Bewerbungen', 'rows' => $rows, 'status' => $status,
            'status_map' => self::STATUS_MAP, 'positions' => ApplicationService::POSITIONS,
        ]))->withHeader('Cache-Control', 'no-store');
    }

    public function show(Request $req, AdminContext $ctx, string $id): Response
    {
        $a = $this->find($id);
        if ($a['status'] === 'new') {
            $this->app->db->run("UPDATE applications SET status = 'in_progress' WHERE id = ?", [(int) $id]);
            $a['status'] = 'in_progress';
        }
        return Response::html($this->app->view->render('admin/applications/show.twig', [
            'title' => 'Bewerbung ' . $a['first_name'] . ' ' . $a['last_name'], 'a' => $a,
            'files' => $this->app->db->all('SELECT id, original_name, size_bytes FROM application_files WHERE application_id = ? ORDER BY id', [(int) $id]),
            'status_map' => self::STATUS_MAP, 'positions' => ApplicationService::POSITIONS,
        ]))->withHeader('Cache-Control', 'no-store');
    }

    public function download(Request $req, AdminContext $ctx, string $id, string $file): Response
    {
        $a = $this->find($id);
        $f = $this->app->applications()->readFile((int) $id, (int) $file);
        if ($f === null) {
            throw HttpException::notFound();
        }
        $this->app->audit->record('application.download', 'Unterlage geöffnet: ' . $f['name'], 'application', (int) $a['id']);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name']) ?? 'unterlage.pdf';
        return new Response($f['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => (string) strlen($f['content']),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function setStatus(Request $req, AdminContext $ctx, string $id): Response
    {
        $a = $this->find($id);
        $to = $req->input('value');
        if (isset(self::STATUS_MAP[$to]) && $to !== $a['status']) {
            $this->app->db->run('UPDATE applications SET status = ?, closed_at = ? WHERE id = ?',
                [$to, $to === 'closed' ? gmdate('Y-m-d H:i:s') : null, (int) $id]);
            $this->app->audit->record('application.status', 'Bewerbung ' . self::STATUS_MAP[$to]['label'] . ': ' . $a['first_name'] . ' ' . $a['last_name'], 'application', (int) $id);
            AdminKernel::flash($to === 'closed'
                ? 'Die Bewerbung ist abgeschlossen. Sie wird nach sechs Monaten automatisch gelöscht.'
                : 'Status geändert: ' . self::STATUS_MAP[$to]['label'] . '.');
        }
        return Response::redirect('/verwaltung/bewerbungen/' . (int) $id . '/', 303);
    }

    public function delete(Request $req, AdminContext $ctx, string $id): Response
    {
        $a = $this->find($id);
        $this->app->applications()->delete((int) $id);
        $this->app->audit->record('application.deleted', 'Bewerbung gelöscht (#' . (int) $id . ')', 'application', (int) $id);
        AdminKernel::flash('Die Bewerbung und alle Unterlagen wurden gelöscht.');
        return Response::redirect('/verwaltung/bewerbungen/', 303);
    }
}
