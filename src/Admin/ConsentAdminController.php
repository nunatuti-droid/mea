<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Nachweis der Cookie-Einwilligungen: Übersicht und letzte Einträge (ohne IP-Adressen). */
final class ConsentAdminController
{
    public const ACTIONS = ['accept_all' => 'Alle akzeptiert', 'reject_all' => 'Nur notwendige', 'custom' => 'Eigene Auswahl', 'withdraw' => 'Widerrufen'];

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/einwilligungen/', 'audit', $this->index(...));
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        $id = preg_match('/^[a-f0-9]{32}$/', $req->input('kennung')) ? $req->input('kennung') : '';
        return Response::html($this->app->view->render('admin/consent.twig', [
            'title' => 'Cookie-Einwilligungen',
            'stats' => $db->all('SELECT action, COUNT(*) AS n FROM consent_log WHERE created_at > UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY action'),
            'rows' => $id !== ''
                ? $db->all('SELECT * FROM consent_log WHERE consent_id = ? ORDER BY id DESC', [$id])
                : $db->all('SELECT * FROM consent_log ORDER BY id DESC LIMIT 100'),
            'actions' => self::ACTIONS, 'kennung' => $id,
            'version' => $this->app->consent->version(),
            'categories' => array_column($this->app->consent->categories(), 'label', 'key'),
        ]));
    }
}
