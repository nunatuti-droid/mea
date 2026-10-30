<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Ops\UpdateException;

/** Updates: Paket hochladen, prüfen, installieren, letztes Update zurücknehmen, Wartungsmodus. */
final class UpdateController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/updates/', 'system', $this->index(...));
        $k->post('/verwaltung/updates/hochladen/', 'system', $this->upload(...));
        $k->post('/verwaltung/updates/installieren/', 'system', $this->install(...));
        $k->post('/verwaltung/updates/zuruecknehmen/', 'system', $this->revert(...));
        $k->post('/verwaltung/updates/wartung/', 'system', $this->maintenance(...));
    }

    private function needsReauth(AdminContext $ctx): ?Response
    {
        return $this->app->auth->hasRecentReauth($ctx->session) ? null : Response::redirect('/verwaltung/bestaetigen/?weiter=' . rawurlencode('/verwaltung/updates/'), 303);
    }

    private function incomingDir(): string
    {
        $d = $this->app->root . '/storage/updates';
        if (!is_dir($d)) {
            mkdir($d, 0700, true);
        }
        return $d;
    }

    public function index(Request $req, AdminContext $ctx, ?array $info = null, ?string $token = null, array $log = []): Response
    {
        return Response::html($this->app->view->render('admin/updates/index.twig', [
            'title' => 'Updates', 'version' => $this->app->version(),
            'fingerprint' => $this->app->fileGuard()->keyFingerprint(),
            'history' => $this->app->db->all('SELECT a.*, u.name AS user_name FROM app_updates a LEFT JOIN users u ON u.id = a.installed_by ORDER BY a.id DESC LIMIT 30'),
            'maintenance' => is_file(App::maintenanceFlag($this->app->root)),
            'info' => $info, 'token' => $token, 'log' => $log, 'max_upload' => ini_get('upload_max_filesize'),
            'can_revert' => $this->app->db->value("SELECT 1 FROM app_updates WHERE status = 'installed' LIMIT 1") !== null,
        ]));
    }

    public function upload(Request $req, AdminContext $ctx): Response
    {
        $f = $_FILES['package'] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            AdminKernel::flash('Upload fehlgeschlagen. Größere Pakete bitte per SSH mit bin/console update:install einspielen.', 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        $token = bin2hex(random_bytes(16));
        $path = $this->incomingDir() . '/incoming-' . $token . '.zip';
        move_uploaded_file((string) $f['tmp_name'], $path);
        try {
            $info = $this->app->updates()->inspect($path);
        } catch (UpdateException $e) {
            @unlink($path);
            $this->app->audit->record('update.rejected', 'Updatepaket abgelehnt: ' . $e->getMessage(), 'update', null);
            AdminKernel::flash($e->getMessage(), 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        return $this->index($req, $ctx, $info, $info['problems'] === [] ? $token : null);
    }

    public function install(Request $req, AdminContext $ctx): Response
    {
        $token = $req->input('token');
        $path = $this->incomingDir() . '/incoming-' . $token . '.zip';
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file($path)) {
            AdminKernel::flash('Das Paket ist nicht mehr vorhanden. Bitte erneut hochladen.', 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        if (!$this->app->auth->hasRecentReauth($ctx->session)) {
            AdminKernel::flash('Bitte bestätigen Sie Ihre Identität und laden Sie das Paket danach erneut hoch.', 'error');
            @unlink($path);
            return $this->needsReauth($ctx);
        }
        try {
            $r = $this->app->updates()->install($path, $ctx->id());
            $this->app->audit->record('update.installed', 'Update installiert (Version ' . $this->app->updates()->inspect($path)['version'] . ')', 'update', $r['id']);
            AdminKernel::flash('Update installiert. ' . implode(' ', $r['log']));
        } catch (UpdateException $e) {
            $this->app->audit->record('update.failed', $e->getMessage(), 'update', null);
            (new \Mea\Module\Ops\Notifier($this->app->db, $this->app->mailer))->alert('Update fehlgeschlagen', $e->getMessage(), 'update-' . $token);
            AdminKernel::flash($e->getMessage(), 'error');
        } finally {
            @unlink($path);
        }
        return Response::redirect('/verwaltung/updates/', 303);
    }

    public function revert(Request $req, AdminContext $ctx): Response
    {
        if (($r = $this->needsReauth($ctx)) !== null) {
            return $r;
        }
        try {
            $log = $this->app->updates()->revertLast($ctx->id());
            $this->app->audit->record('update.reverted', 'Letztes Update zurückgenommen', 'update', null);
            AdminKernel::flash('Update zurückgenommen. ' . implode(' ', $log));
        } catch (UpdateException $e) {
            AdminKernel::flash($e->getMessage(), 'error');
        }
        return Response::redirect('/verwaltung/updates/', 303);
    }

    public function maintenance(Request $req, AdminContext $ctx): Response
    {
        $flag = App::maintenanceFlag($this->app->root);
        $on = $req->input('on') === '1';
        $on ? file_put_contents($flag, 'manuell') : @unlink($flag);
        $this->app->audit->record('system.maintenance', $on ? 'Wartungsmodus eingeschaltet' : 'Wartungsmodus ausgeschaltet', 'system', null);
        AdminKernel::flash($on ? 'Wartungsmodus aktiv. Besucher sehen eine Wartungsseite; die Verwaltung bleibt erreichbar.' : 'Wartungsmodus beendet.');
        return Response::redirect('/verwaltung/updates/', 303);
    }
}
