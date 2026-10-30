<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;

/** Änderungsprotokoll: wer hat wann was geändert. Nur lesbar, nicht änderbar. */
final class AuditController
{
    private const PER_PAGE = 50;

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/protokoll/', 'audit', $this->index(...));
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        $where = [];
        $params = [];
        $query = [];
        if (ctype_digit($req->input('benutzer'))) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $req->input('benutzer');
            $query['benutzer'] = $req->input('benutzer');
        }
        $area = $req->input('bereich');
        if ($area !== '' && preg_match('/^[a-z_]{2,40}$/', $area)) {
            $where[] = 'a.action LIKE ?';
            $params[] = $area . '.%';
            $query['bereich'] = $area;
        }
        foreach (['von' => '>=', 'bis' => '<'] as $key => $op) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $req->input($key), new \DateTimeZone('Europe/Berlin'));
            if ($d !== false) {
                $where[] = "a.created_at $op ?";
                $params[] = ($key === 'bis' ? $d->modify('+1 day') : $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $query[$key] = $req->input($key);
            }
        }
        $q = mb_substr($req->input('q'), 0, 100);
        if ($q !== '') {
            $where[] = 'a.summary LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
            $query['q'] = $q;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) $db->value('SELECT COUNT(*) FROM audit_log a' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $req->input('seite')));
        $rows = $db->all(
            'SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . $sqlWhere
            . ' ORDER BY a.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params,
        );
        foreach ($rows as &$r) {
            $d = $r['details_json'] ? json_decode((string) $r['details_json'], true) : null;
            $r['details'] = is_array($d) && $d !== [] ? json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        unset($r);
        $areas = array_column($db->all("SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) AS a FROM audit_log ORDER BY a"), 'a');
        return Response::html($this->app->view->render('admin/audit/index.twig', [
            'title' => 'Änderungsprotokoll', 'rows' => $rows, 'query' => $query, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'users' => $db->all('SELECT id, name FROM users ORDER BY name'), 'areas' => $areas, 'area_labels' => self::AREAS,
        ]));
    }

    private const AREAS = [
        'auth' => 'Anmeldung', 'user' => 'Benutzer', 'exam_type' => 'Prüfungsarten', 'exam_provider' => 'Anbieter', 'booking_rule' => 'Buchungsregeln',
        'exam_session' => 'Termine', 'session' => 'Termine (Benachrichtigung)', 'holiday' => 'Feiertage', 'booking' => 'Buchungen', 'payment' => 'Zahlungen',
        'page' => 'Seiten', 'service' => 'Leistungen', 'faq' => 'FAQ', 'review' => 'Bewertungen', 'menu_item' => 'Menüs', 'location' => 'Standorte',
        'media' => 'Medien', 'settings' => 'Einstellungen', 'inquiry' => 'Anfragen', 'email' => 'E-Mail', 'email_template' => 'E-Mail-Vorlagen',
    ];
}
