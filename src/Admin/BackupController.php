<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Ops\BackupException;

/** Sicherungen: anlegen, prüfen, herunterladen, hochladen, wiederherstellen, löschen. */
final class BackupController
{
    public const KINDS = ['manual' => 'Manuell', 'nightly' => 'Nächtlich', 'pre_restore' => 'Vor Wiederherstellung', 'pre_update' => 'Vor Update', 'uploaded' => 'Hochgeladen'];

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/sicherungen/', 'system', $this->index(...));
        $k->post('/verwaltung/sicherungen/erstellen/', 'system', $this->create(...));
        $k->post('/verwaltung/sicherungen/hochladen/', 'system', $this->upload(...));
        $k->post('/verwaltung/sicherungen/{id:\d+}/pruefen/', 'system', $this->verify(...));
        $k->get('/verwaltung/sicherungen/{id:\d+}/herunterladen/', 'system', $this->download(...));
        $k->get('/verwaltung/sicherungen/{id:\d+}/zip/', 'system', $this->zipForm(...));
        $k->post('/verwaltung/sicherungen/{id:\d+}/zip/', 'system', $this->zipDownload(...));
        $k->get('/verwaltung/sicherungen/{id:\d+}/wiederherstellen/', 'system', $this->restoreForm(...));
        $k->post('/verwaltung/sicherungen/{id:\d+}/wiederherstellen/', 'system', $this->restore(...));
        $k->post('/verwaltung/sicherungen/{id:\d+}/loeschen/', 'system', $this->delete(...));
    }

    private function needsReauth(AdminContext $ctx, string $back): ?Response
    {
        return $this->app->auth->hasRecentReauth($ctx->session) ? null : Response::redirect('/verwaltung/bestaetigen/?weiter=' . rawurlencode($back), 303);
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        return Response::html($this->app->view->render('admin/backups/index.twig', [
            'title' => 'Sicherungen',
            'backups' => $db->all('SELECT b.*, u.name AS user_name FROM backups b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 100'),
            'last_ok' => $db->one("SELECT * FROM backups WHERE status = 'ok' ORDER BY id DESC LIMIT 1"),
            'last_download' => $db->value('SELECT MAX(downloaded_at) FROM backups'),
            'job' => $db->one("SELECT * FROM cron_runs WHERE job = 'backup'"),
            'kinds' => self::KINDS, 'own_key' => $this->app->config->get('BACKUP_KEY') !== null,
            'max_upload' => ini_get('upload_max_filesize'),
        ]));
    }

    public function create(Request $req, AdminContext $ctx): Response
    {
        try {
            $id = $this->app->backups()->create('manual', $ctx->id(), mb_substr($req->input('note'), 0, 200));
            $this->app->audit->record('backup.created', 'Sicherung erstellt', 'backup', $id);
            AdminKernel::flash('Sicherung erstellt und geprüft.');
        } catch (BackupException $e) {
            AdminKernel::flash('Sicherung fehlgeschlagen: ' . $e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/sicherungen/', 303);
    }

    public function verify(Request $req, AdminContext $ctx, string $id): Response
    {
        try {
            $m = $this->app->backups()->verify((int) $id);
            AdminKernel::flash('Sicherung ist vollständig und unverändert (' . count($m['tables']) . ' Tabellen, ' . count(preg_grep('#^files/#', array_keys($m['files']))) . ' Dateien).');
        } catch (BackupException $e) {
            AdminKernel::flash('Prüfung fehlgeschlagen: ' . $e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/sicherungen/', 303);
    }

    public function download(Request $req, AdminContext $ctx, string $id): Response
    {
        if (($r = $this->needsReauth($ctx, '/verwaltung/sicherungen/')) !== null) {
            return $r;
        }
        try {
            $b = $this->app->backups()->get((int) $id);
        } catch (BackupException) {
            throw HttpException::notFound();
        }
        $this->app->db->run('UPDATE backups SET downloaded_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $id]);
        $this->app->audit->record('backup.downloaded', 'Sicherung heruntergeladen: ' . $b['file_name'], 'backup', (int) $id);
        return Response::download($this->app->backups()->path($b), (string) $b['file_name']);
    }

    public function zipForm(Request $req, AdminContext $ctx, string $id, ?string $error = null): Response
    {
        if (($r = $this->needsReauth($ctx, '/verwaltung/sicherungen/' . (int) $id . '/zip/')) !== null) {
            return $r;
        }
        try {
            $b = $this->app->backups()->get((int) $id);
        } catch (BackupException) {
            throw HttpException::notFound();
        }
        return Response::html($this->app->view->render('admin/backups/zip.twig', ['title' => 'Sicherung als ZIP herunterladen', 'b' => $b, 'kinds' => self::KINDS,
            'error' => $error, 'min' => \Mea\Module\Ops\BackupService::ZIP_PASSWORD_MIN]), $error ? 422 : 200);
    }

    /** Sicherung als ZIP mit eigenem Passwort (AES-256). Die temporäre Datei wird nach dem Senden gelöscht. */
    public function zipDownload(Request $req, AdminContext $ctx, string $id): Response
    {
        if (($r = $this->needsReauth($ctx, '/verwaltung/sicherungen/' . (int) $id . '/zip/')) !== null) {
            return $r;
        }
        $pw = (string) $req->input('password', '');
        if ($pw !== (string) $req->input('password2', '')) {
            return $this->zipForm($req, $ctx, $id, 'Die beiden Passwörter stimmen nicht überein.');
        }
        try {
            $b = $this->app->backups()->get((int) $id);
            $zip = $this->app->backups()->exportZip((int) $id, $pw);
        } catch (BackupException $e) {
            return $this->zipForm($req, $ctx, $id, $e->getMessage());
        }
        $this->app->db->run('UPDATE backups SET downloaded_at = UTC_TIMESTAMP() WHERE id = ?', [(int) $id]);
        $this->app->audit->record('backup.downloaded', 'Sicherung als ZIP mit Passwort heruntergeladen: ' . $b['file_name'], 'backup', (int) $id);
        $backups = $this->app->backups();
        return Response::download($zip, basename($zip), 'application/zip')->then(fn() => $backups->removeExport($zip));
    }

    public function upload(Request $req, AdminContext $ctx): Response
    {
        if (($r = $this->needsReauth($ctx, '/verwaltung/sicherungen/')) !== null) {
            return $r;
        }
        $f = $_FILES['backup'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            AdminKernel::flash('Upload fehlgeschlagen. Ist die Datei größer als ' . ini_get('upload_max_filesize') . '? Dann bitte per SSH in storage/backups ablegen.', 'error');
            return Response::redirect('/verwaltung/sicherungen/', 303);
        }
        try {
            $id = $this->app->backups()->import((string) $f['tmp_name'], $ctx->id(), (string) $req->input('zip_password', ''));
            $this->app->audit->record('backup.uploaded', 'Sicherung hochgeladen', 'backup', $id);
            AdminKernel::flash('Sicherung hochgeladen und geprüft. Sie kann jetzt wiederhergestellt werden.');
        } catch (BackupException $e) {
            AdminKernel::flash('Die Datei wurde nicht übernommen: ' . $e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/sicherungen/', 303);
    }

    public function restoreForm(Request $req, AdminContext $ctx, string $id, ?string $error = null): Response
    {
        try {
            $b = $this->app->backups()->get((int) $id);
        } catch (BackupException) {
            throw HttpException::notFound();
        }
        return Response::html($this->app->view->render('admin/backups/restore.twig', ['title' => 'Sicherung wiederherstellen', 'b' => $b, 'kinds' => self::KINDS, 'error' => $error]), $error ? 422 : 200);
    }

    public function restore(Request $req, AdminContext $ctx, string $id): Response
    {
        $back = '/verwaltung/sicherungen/' . (int) $id . '/wiederherstellen/';
        if (($r = $this->needsReauth($ctx, $back)) !== null) {
            return $r;
        }
        if ($req->input('confirm') !== 'WIEDERHERSTELLEN') {
            return $this->restoreForm($req, $ctx, $id, 'Bitte geben Sie zur Bestätigung WIEDERHERSTELLEN ein.');
        }
        try {
            $res = $this->app->backups()->restore((int) $id, $ctx->id());
            $this->app->audit->record('backup.restored', 'Sicherung #' . (int) $id . ' wiederhergestellt (vorher gesichert als #' . $res['pre_backup'] . ')', 'backup', (int) $id);
            AdminKernel::flash('Wiederherstellung abgeschlossen. ' . implode(' ', $res['log']));
        } catch (BackupException $e) {
            (new \Mea\Module\Ops\Notifier($this->app->db, $this->app->mailer))->alert('Wiederherstellung fehlgeschlagen', $e->getMessage(), 'restore-' . (int) $id . '-' . time());
            AdminKernel::flash($e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/sicherungen/', 303);
    }

    public function delete(Request $req, AdminContext $ctx, string $id): Response
    {
        $b = $this->app->db->one('SELECT * FROM backups WHERE id = ?', [(int) $id]);
        if ($b !== null && $b['status'] !== 'running') {
            @unlink($this->app->backups()->dir() . '/' . basename((string) $b['file_name']));
            $this->app->db->run('DELETE FROM backups WHERE id = ?', [(int) $id]);
            $this->app->audit->record('backup.deleted', 'Sicherung gelöscht: ' . $b['file_name'], 'backup', (int) $id);
            AdminKernel::flash('Sicherung gelöscht.');
        }
        return Response::redirect('/verwaltung/sicherungen/', 303);
    }
}
