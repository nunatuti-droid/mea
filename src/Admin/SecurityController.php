<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Security\IpAddress;

/** Sicherheitsprotokoll, aktive Sperren, Sperr- und Freigabelisten. */
final class SecurityController
{
    private const PER_PAGE = 50;
    public const TYPES = [
        'path_traversal' => 'Verzeichnis-Ausbruch', 'sql_injection' => 'SQL-Einschleusung', 'xss_attempt' => 'Skript-Einschleusung',
        'code_injection' => 'Code-Einschleusung', 'probe' => 'Suche nach Schwachstellen', 'login_failed' => 'Fehlgeschlagene Anmeldung',
        '2fa_failed' => 'Falscher Zwei-Faktor-Code', 'auto_ban' => 'Automatische Sperre', 'integrity' => 'Dateiwächter',
    ];

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/sicherheit/', 'system', $this->index(...));
        $k->post('/verwaltung/sicherheit/regeln/', 'system', $this->addRule(...));
        $k->post('/verwaltung/sicherheit/regeln/{id:\d+}/entfernen/', 'system', $this->removeRule(...));
    }

    public function index(Request $req, AdminContext $ctx, array $errors = [], array $values = []): Response
    {
        $db = $this->app->db;
        $type = array_key_exists($req->input('art'), self::TYPES) ? $req->input('art') : '';
        $where = $type !== '' ? ' WHERE event_type = ?' : '';
        $params = $type !== '' ? [$type] : [];
        $total = (int) $db->value('SELECT COUNT(*) FROM security_events' . $where, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $req->input('seite')));
        return Response::html($this->app->view->render('admin/security.twig', [
            'title' => 'Sicherheit',
            'events' => $db->all('SELECT * FROM security_events' . $where . ' ORDER BY id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params),
            'bans' => $db->all("SELECT * FROM ip_rules WHERE source = 'auto' AND expires_at > UTC_TIMESTAMP() ORDER BY expires_at DESC"),
            'rules' => $db->all("SELECT r.*, u.name AS user_name FROM ip_rules r LEFT JOIN users u ON u.id = r.created_by WHERE r.source = 'manual' AND (r.expires_at IS NULL OR r.expires_at > UTC_TIMESTAMP()) ORDER BY r.rule, r.id"),
            'stats' => $db->one("SELECT SUM(created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY) AS day, SUM(created_at > UTC_TIMESTAMP() - INTERVAL 7 DAY) AS week FROM security_events") ?? [],
            'types' => self::TYPES, 'type' => $type, 'page' => $page, 'pages' => $pages, 'query' => $type !== '' ? ['art' => $type] : [],
            'my_ip' => $req->ip(), 'errors' => $errors, 'values' => $values + ['rule' => 'block', 'days' => ''],
        ]), $errors ? 422 : 200);
    }

    public function addRule(Request $req, AdminContext $ctx): Response
    {
        $rule = $req->input('rule') === 'allow' ? 'allow' : 'block';
        $cidr = trim($req->input('cidr'));
        $reason = trim(mb_substr($req->input('reason'), 0, 255));
        $days = $req->input('days');
        $errors = [];
        if (!IpAddress::validCidr($cidr)) {
            $errors['cidr'] = 'Bitte eine gültige IP-Adresse oder einen Bereich angeben, z. B. 203.0.113.7 oder 203.0.113.0/24.';
        } elseif ($rule === 'block' && IpAddress::inCidr($req->ip(), $cidr)) {
            $errors['cidr'] = 'Dieser Bereich enthält Ihre eigene Adresse. Sie würden sich selbst aussperren.';
        }
        if ($reason === '') {
            $errors['reason'] = 'Bitte einen Grund angeben.';
        }
        if ($days !== '' && (!ctype_digit($days) || (int) $days < 1 || (int) $days > 3650)) {
            $errors['days'] = 'Bitte eine Zahl von 1 bis 3650 oder leer für unbegrenzt.';
        }
        if ($errors !== []) {
            return $this->index($req, $ctx, $errors, ['rule' => $rule, 'cidr' => $cidr, 'reason' => $reason, 'days' => $days]);
        }
        $id = $this->app->db->insert('ip_rules', [
            'rule' => $rule, 'cidr' => $cidr, 'reason' => $reason, 'source' => 'manual', 'created_by' => $ctx->id(),
            'expires_at' => $days !== '' ? gmdate('Y-m-d H:i:s', time() + (int) $days * 86400) : null,
        ]);
        $this->app->audit->record('security.rule_added', ($rule === 'block' ? 'Sperre' : 'Freigabe') . " für $cidr: $reason", 'ip_rule', $id);
        AdminKernel::flash(($rule === 'block' ? 'Sperre' : 'Freigabe') . ' gespeichert.');
        return Response::redirect('/verwaltung/sicherheit/#regeln', 303);
    }

    public function removeRule(Request $req, AdminContext $ctx, string $id): Response
    {
        $r = $this->app->db->one('SELECT * FROM ip_rules WHERE id = ?', [(int) $id]);
        if ($r !== null) {
            $this->app->db->run('DELETE FROM ip_rules WHERE id = ?', [(int) $id]);
            $this->app->audit->record('security.rule_removed', 'Regel entfernt: ' . ($r['cidr'] ?? $r['ip_masked']) . ' (' . $r['reason'] . ')', 'ip_rule', (int) $id);
            AdminKernel::flash('Regel entfernt.');
        }
        return Response::redirect('/verwaltung/sicherheit/', 303);
    }
}
