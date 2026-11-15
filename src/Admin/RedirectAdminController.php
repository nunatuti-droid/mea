<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Weiterleitungen: „Nicht gefundene Adressen“ als erledigt ausblenden (einzeln oder alle). */
final class RedirectAdminController
{
    private const BASE = '/verwaltung/weiterleitungen/';

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->post(self::BASE . 'nicht-gefunden/ausblenden/', 'content', $this->hide(...));
        $k->post(self::BASE . 'nicht-gefunden/alle-ausblenden/', 'content', $this->hideAll(...));
    }

    public function hide(Request $req, AdminContext $ctx): Response
    {
        $path = mb_substr((string) $req->input('path'), 0, 500);
        $n = $path === '' ? 0 : $this->app->db->run('UPDATE not_found_log SET resolved_at = UTC_TIMESTAMP() WHERE path = ? AND resolved_at IS NULL', [$path]);
        if ($n > 0) {
            $this->app->audit->record('redirect.not_found_hidden', 'Nicht gefundene Adresse ausgeblendet: ' . $path, 'redirect', null);
            AdminKernel::flash('Die Adresse wurde ausgeblendet. Sie erscheint erst wieder, wenn sie erneut aufgerufen wird.');
        }
        return Response::redirect(self::BASE, 303);
    }

    public function hideAll(Request $req, AdminContext $ctx): Response
    {
        $n = $this->app->db->run('UPDATE not_found_log SET resolved_at = UTC_TIMESTAMP() WHERE resolved_at IS NULL');
        if ($n > 0) {
            $this->app->audit->record('redirect.not_found_hidden_all', $n . ' nicht gefundene Adressen ausgeblendet', 'redirect', null, ['anzahl' => $n]);
        }
        AdminKernel::flash($n > 0 ? $n . ' Adressen wurden ausgeblendet.' : 'Es gab nichts auszublenden.');
        return Response::redirect(self::BASE, 303);
    }
}
