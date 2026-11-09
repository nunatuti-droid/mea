<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Ops\UpdateException;
use Mea\Module\Ops\UpdateJobs;

/** Updates: Paket hochladen, prüfen, installieren, letztes Update zurücknehmen, Wartungsmodus. */
final class UpdateController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/updates/', 'system', $this->index(...));
        $k->post('/verwaltung/updates/hochladen/', 'system', $this->upload(...));
        $k->post('/verwaltung/updates/hochladen-teil/', 'system', $this->uploadChunk(...));
        $k->get('/verwaltung/updates/pruefen/{token:[a-f0-9]{32}}/', 'system', $this->check(...));
        $k->post('/verwaltung/updates/installieren/', 'system', $this->install(...));
        $k->get('/verwaltung/updates/fortschritt/{token:[a-f0-9]{32}}/', 'system', $this->progress(...));
        $k->get('/verwaltung/updates/status/{token:[a-f0-9]{32}}/', 'system', $this->status(...));
        $k->post('/verwaltung/updates/ausfuehren/', 'system', $this->run(...));
        $k->post('/verwaltung/updates/zuruecknehmen/', 'system', $this->revert(...));
        $k->post('/verwaltung/updates/wartung/', 'system', $this->maintenance(...));
    }

    private function needsReauth(AdminContext $ctx): ?Response
    {
        return $this->app->auth->hasRecentReauth($ctx->session) ? null : Response::redirect('/verwaltung/bestaetigen/?weiter=' . rawurlencode('/verwaltung/updates/'), 303);
    }

    private const MAX_PACKAGE = 100 * 1024 * 1024;

    private function jobs(): UpdateJobs
    {
        return new UpdateJobs($this->app);
    }

    private function incomingDir(): string
    {
        return $this->jobs()->dir();
    }

    /** Läuft der minütliche Cron? Dann übernimmt er die Installation (ohne Zeitgrenzen des Webservers). */
    private function cronActive(): bool
    {
        $last = (string) ($this->app->settings->get('cron_last_run') ?? '');
        return $last !== '' && time() - (int) strtotime($last . ' UTC') < 180;
    }

    /** Größe eines Upload-Teils: sicher unter den PHP-Grenzen des Servers. */
    private static function chunkSize(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        $limits = array_filter([$toBytes((string) ini_get('upload_max_filesize')), $toBytes((string) ini_get('post_max_size'))], fn($x) => $x > 0);
        $limit = $limits === [] ? 2 << 20 : min($limits);
        return max(256 << 10, min(2 << 20, intdiv($limit, 2)));
    }

    public function index(Request $req, AdminContext $ctx, ?array $info = null, ?string $token = null, array $log = []): Response
    {
        return Response::html($this->app->view->render('admin/updates/index.twig', [
            'title' => 'Updates', 'version' => $this->app->version(),
            'fingerprint' => $this->app->fileGuard()->keyFingerprint(),
            'history' => $this->app->db->all('SELECT a.*, u.name AS user_name FROM app_updates a LEFT JOIN users u ON u.id = a.installed_by ORDER BY a.id DESC LIMIT 30'),
            'maintenance' => is_file(App::maintenanceFlag($this->app->root)),
            'info' => $info, 'token' => $token, 'log' => $log, 'chunk_size' => self::chunkSize(),
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
        return $this->inspectAndShow($req, $ctx, $token);
    }

    /** Ein Teil eines großen Pakets (der Browser zerlegt die Datei, damit Upload-Grenzen des Servers nicht greifen). */
    public function uploadChunk(Request $req, AdminContext $ctx): Response
    {
        $id = (string) $req->input('upload_id');
        $index = (int) $req->input('index');
        $total = (int) $req->input('total');
        $f = $_FILES['chunk'] ?? null;
        if (!UpdateJobs::validToken($id) || $total < 1 || $total > 1000 || $index < 0 || $index >= $total) {
            return Response::json(['ok' => false, 'error' => 'Ungültige Anfrage.'], 400);
        }
        if (!is_array($f) || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            return Response::json(['ok' => false, 'error' => 'Ein Teil des Pakets kam nicht an. Bitte erneut versuchen.'], 400);
        }
        $part = $this->incomingDir() . '/upload-' . $id . '.part';
        $state = $part . '.json';
        $expected = $index === 0 ? 0 : (int) (json_decode((string) @file_get_contents($state), true)['next'] ?? -1);
        if ($index !== $expected) {
            return Response::json(['ok' => false, 'error' => 'Die Teile kamen in falscher Reihenfolge an. Bitte erneut hochladen.'], 409);
        }
        $data = (string) file_get_contents((string) $f['tmp_name']);
        if (file_put_contents($part, $data, $index === 0 ? 0 : FILE_APPEND) === false || filesize($part) > self::MAX_PACKAGE) {
            @unlink($part);
            @unlink($state);
            return Response::json(['ok' => false, 'error' => 'Das Paket ist zu groß oder kann nicht gespeichert werden.'], 400);
        }
        clearstatcache();
        if ($index + 1 < $total) {
            file_put_contents($state, json_encode(['next' => $index + 1]));
            return Response::json(['ok' => true]);
        }
        @unlink($state);
        rename($part, $this->incomingDir() . '/incoming-' . $id . '.zip');
        return Response::json(['ok' => true, 'next' => '/verwaltung/updates/pruefen/' . $id . '/']);
    }

    public function check(Request $req, AdminContext $ctx, string $token): Response
    {
        if (!is_file($this->incomingDir() . '/incoming-' . $token . '.zip')) {
            AdminKernel::flash('Das Paket ist nicht mehr vorhanden. Bitte erneut hochladen.', 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        return $this->inspectAndShow($req, $ctx, $token);
    }

    private function inspectAndShow(Request $req, AdminContext $ctx, string $token): Response
    {
        $path = $this->incomingDir() . '/incoming-' . $token . '.zip';
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
            AdminKernel::flash('Bitte bestätigen Sie kurz Ihre Identität. Danach klicken Sie noch einmal auf „Update jetzt installieren“.');
            return Response::redirect('/verwaltung/bestaetigen/?weiter=' . rawurlencode('/verwaltung/updates/pruefen/' . $token . '/'), 303);
        }
        try {
            $info = $this->app->updates()->inspect($path);
        } catch (UpdateException $e) {
            @unlink($path);
            AdminKernel::flash($e->getMessage(), 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        if ($info['problems'] !== []) {
            @unlink($path);
            AdminKernel::flash(implode(' ', $info['problems']), 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        $this->jobs()->create($token, $info['version'], $ctx->id());
        $this->app->audit->record('update.queued', 'Update auf Version ' . $info['version'] . ' gestartet', 'update', null);
        return Response::redirect('/verwaltung/updates/fortschritt/' . $token . '/', 303);
    }

    public function progress(Request $req, AdminContext $ctx, string $token): Response
    {
        $job = $this->jobs()->get($token);
        if ($job === null) {
            AdminKernel::flash('Diesen Update-Auftrag gibt es nicht (mehr).', 'error');
            return Response::redirect('/verwaltung/updates/', 303);
        }
        return Response::html($this->app->view->render('admin/updates/progress.twig', [
            'title' => 'Update läuft', 'job' => $job, 'token' => $token, 'cron' => $this->cronActive(),
        ]));
    }

    public function status(Request $req, AdminContext $ctx, string $token): Response
    {
        $job = $this->jobs()->get($token);
        if ($job === null) {
            return Response::json(['status' => 'missing'], 404);
        }
        $stale = $job['status'] === 'running' && time() - (int) ($job['updated_at'] ?? 0) > 900;
        return Response::json(['status' => $job['status'], 'log' => $job['log'] ?? [], 'message' => $job['message'] ?? '', 'version' => $job['version'], 'stale' => $stale])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Startet einen wartenden Auftrag im Hintergrund dieser Anfrage (wenn kein Cron läuft oder er nicht anspringt). */
    public function run(Request $req, AdminContext $ctx): Response
    {
        $token = (string) $req->input('token');
        $job = $this->jobs()->get($token);
        if ($job === null || $job['status'] !== 'queued') {
            return Response::json(['started' => false]);
        }
        $jobs = $this->jobs();
        return Response::json(['started' => true])->then(static function () use ($jobs, $token): void {
            $jobs->run($token);
        });
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
