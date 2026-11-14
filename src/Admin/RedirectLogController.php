<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Einträge im Protokoll „Nicht gefundene Adressen“ ausblenden (z. B. Bot-Abfragen). */
final class RedirectLogController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->post('/verwaltung/weiterleitungen/nicht-gefunden/ausblenden/', 'content', $this->hide(...));
    }

    /** Ein Eintrag (path) oder alle offenen (alle=1). Ein erneuter Aufruf der Adresse macht den Eintrag wieder sichtbar. */
    public function hide(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        if ($req->input('alle') === '1') {
            $n = $db->run('UPDATE not_found_log SET resolved_at = UTC_TIMESTAMP() WHERE resolved_at IS NULL');
        } else {
            $path = trim((string) $req->input('path'));
            $n = $path === '' ? 0 : $db->run('UPDATE not_found_log SET resolved_at = UTC_TIMESTAMP() WHERE resolved_at IS NULL AND path = ?', [$path]);
        }
        if ($n > 0) {
            $this->app->audit->record('redirect.not_found_hidden', "Nicht gefundene Adressen ausgeblendet: $n", 'not_found_log', null);
        }
        AdminKernel::flash($n > 0 ? "$n Eintrag ausgeblendet." : 'Kein Eintrag ausgeblendet.');
        return Response::redirect('/verwaltung/weiterleitungen/', 303);
    }
}
