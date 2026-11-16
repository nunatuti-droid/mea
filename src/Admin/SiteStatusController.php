<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Website online/offline und Suchmaschinen-Indexierung mit je einem Schalter. */
final class SiteStatusController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/website-status/', 'system', $this->edit(...));
        $k->post('/verwaltung/website-status/', 'system', $this->save(...));
    }

    public function edit(Request $req, AdminContext $ctx, array $errors = [], ?string $message = null): Response
    {
        $s = $this->app->siteStatus;
        return Response::html($this->app->view->render('admin/site/status.twig', [
            'title' => 'Website-Status',
            'online' => $s->isOnline(),
            'indexing' => $s->indexingAllowed(),
            'message' => $message ?? $s->offlineMessage(),
            'errors' => $errors,
            'videos' => $this->app->db->all("SELECT id, COALESCE(NULLIF(title, ''), original_name) AS name FROM media WHERE kind = 'video' ORDER BY id DESC"),
            'video_desktop' => $s->videoId('desktop'),
            'video_mobile' => $s->videoId('mobile'),
            'base_url' => rtrim($this->app->baseUrl, '/'),
        ]), $errors ? 422 : 200);
    }

    public function save(Request $req, AdminContext $ctx): Response
    {
        $s = $this->app->siteStatus;
        $online = $req->input('online') === '1';
        $indexing = $req->input('indexing') === '1';
        $message = trim(str_replace("\r\n", "\n", $req->input('message')));
        if (mb_strlen($message) > 600) {
            return $this->edit($req, $ctx, ['message' => 'Bitte höchstens 600 Zeichen.'], $message);
        }
        $before = ['online' => $s->isOnline(), 'indexing' => $s->indexingAllowed()];
        $vid = function (string $field) use ($req): ?int {
            $id = (int) $this->app->db->value("SELECT id FROM media WHERE id = ? AND kind = 'video'", [(int) $req->input($field)]);
            return $id > 0 ? $id : null;
        };
        $s->save(['online' => $online, 'indexing' => $indexing, 'message' => strip_tags($message),
            'video_desktop' => $vid('video_desktop'), 'video_mobile' => $vid('video_mobile')]);

        $changes = [];
        if ($before['online'] !== $online) {
            $changes[] = $online ? 'Website online geschaltet' : 'Website offline geschaltet (Wartungsseite)';
        }
        if ($before['indexing'] !== $indexing) {
            $changes[] = $indexing ? 'Suchmaschinen-Indexierung erlaubt' : 'Suchmaschinen-Indexierung gesperrt';
        }
        $this->app->audit->record('site.status', $changes !== [] ? implode('; ', $changes) : 'Hinweistext der Wartungsseite geändert', 'setting', null);
        AdminKernel::flash($changes !== [] ? implode('. ', $changes) . '.' : 'Gespeichert.');
        return Response::redirect('/verwaltung/website-status/', 303);
    }
}
