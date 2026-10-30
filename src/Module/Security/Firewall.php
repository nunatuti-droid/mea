<?php
declare(strict_types=1);

namespace Mea\Module\Security;

use Mea\Core\Crypto;
use Mea\Core\Database;
use Mea\Core\Request;
use Psr\Clock\ClockInterface;

/**
 * Anwendungsschutz vor dem Routing: Sperr- und Freigabelisten, Erkennung typischer
 * Angriffsmuster, Punktekonto je IP und zeitlich begrenzte automatische Sperren.
 * Ersetzt keine Firewall des Hosters. IP-Adressen werden nur als Hash und gekürzt gespeichert.
 */
final class Firewall
{
    /** Punkte innerhalb des Fensters, ab denen gesperrt wird */
    public const BAN_THRESHOLD = 10;
    public const WINDOW_MINUTES = 10;
    public const BAN_MINUTES = 60;
    public const REPEAT_BAN_HOURS = 24;

    /** Muster => [Ereignis, Punkte, sofort ablehnen] */
    private const PATTERNS = [
        '#(\.\./|\.\.\\\\|%2e%2e|%252e|\x00|%00)#i' => ['path_traversal', 5, true],
        '#/(etc/passwd|proc/self|win\.ini|boot\.ini)#i' => ['path_traversal', 5, true],
        '#(\bunion\b[\s+/*]+(all[\s+/*]+)?select\b|\binformation_schema\b|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|\bwaitfor\s+delay\b|\'\s*or\s+\'?1\'?\s*=\s*\'?1)#i' => ['sql_injection', 5, true],
        '#(<\s*script\b|javascript:|onerror\s*=|onload\s*=|<\s*svg[^>]*on\w+\s*=|<\s*iframe\b)#i' => ['xss_attempt', 4, true],
        '#(\beval\s*\(|base64_decode\s*\(|\bsystem\s*\(|\bshell_exec\b|\bpassthru\b|\$\{jndi:|<\?php)#i' => ['code_injection', 5, true],
        '#/(wp-login\.php|xmlrpc\.php|wp-config|phpmyadmin|pma/|adminer|\.env|\.git/|\.svn/|\.ht(access|passwd)|composer\.(json|lock)|vendor/phpunit|phpinfo|server-status|cgi-bin/|shell\.php|config\.php|storage/|integrity\.(json|sig))#i' => ['probe', 2, false],
    ];

    /** Bekannte Angriffs- und Scan-Werkzeuge (User-Agent). Normale Browser und Suchmaschinen sind nicht betroffen. */
    private const BAD_AGENTS = '#(sqlmap|nikto|nmap|masscan|zgrab|acunetix|nessus|openvas|wpscan|dirbuster|gobuster|feroxbuster|ffuf|nuclei|w3af|havij|jorgee|zmeu|morfeus|fimap|libwww-perl|python-urllib)#i';

    /** Erlaubte HTTP-Methoden; alles andere (TRACE, PUT, DELETE …) wird abgelehnt. */
    public const METHODS = ['GET', 'HEAD', 'POST'];

    /** Einstellung: IP-Bereiche, aus denen die Verwaltung erreichbar ist (leer = von überall). */
    public const ADMIN_ACCESS_KEY = 'admin_access';

    public function __construct(private Database $db, private Crypto $crypto, private ClockInterface $clock) {}

    /** @return list<string> */
    public function adminRanges(): array
    {
        $v = $this->db->value('SELECT value_json FROM settings WHERE setting_key = ?', [self::ADMIN_ACCESS_KEY]);
        $d = $v !== null ? json_decode((string) $v, true) : null;
        return is_array($d) ? array_values(array_filter(array_map('strval', $d['ranges'] ?? []), [IpAddress::class, 'validCidr'])) : [];
    }

    /** Darf diese Adresse die Verwaltung öffnen? Ohne Einschränkung immer ja. */
    public function adminReachable(string $ip): bool
    {
        $ranges = $this->adminRanges();
        if ($ranges === []) {
            return true;
        }
        foreach ($ranges as $cidr) {
            if (IpAddress::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{action:'allow'|'block'|'reject'|'probe', reason:string}
     *   block = gesperrte IP, reject = Angriffsmuster (403), probe = Scanner (404), allow = weiter
     */
    public function inspect(Request $req): array
    {
        $ip = $req->ip();
        if ($this->isAllowed($ip)) {
            return ['action' => 'allow', 'reason' => 'allowlist'];
        }
        if ($this->isBlocked($ip)) {
            return ['action' => 'block', 'reason' => 'blocked'];
        }
        if (!in_array(strtoupper($req->method), self::METHODS, true)) {
            $this->record('bad_method', $req, 2, 'warning', mb_substr($req->method, 0, 20));
            return ['action' => 'method', 'reason' => 'bad_method'];
        }
        if (preg_match(self::BAD_AGENTS, $req->userAgent())) {
            $this->record('bad_bot', $req, 5, 'warning', mb_substr($req->userAgent(), 0, 120));
            return ['action' => 'reject', 'reason' => 'bad_bot'];
        }
        if (str_starts_with($req->path, '/verwaltung/') && !$this->adminReachable($ip)) {
            $this->record('admin_denied', $req, 1, 'warning');
            return ['action' => 'probe', 'reason' => 'admin_denied'];
        }
        // Schlüssel und Werte der Abfrage im Klartext prüfen (http_build_query würde Leerzeichen als + kodieren)
        $parts = [];
        $query = $req->query;
        array_walk_recursive($query, function ($v, $k) use (&$parts): void {
            $parts[] = $k . '=' . $v;
        });
        foreach (array_keys($req->query) as $k) {
            $parts[] = (string) $k;
        }
        $subject = rawurldecode(rawurldecode($req->path)) . ' ' . rawurldecode(implode(' ', $parts));
        foreach (self::PATTERNS as $re => [$type, $points, $reject]) {
            if (preg_match($re, $subject) || preg_match($re, $req->path)) {
                $this->record($type, $req, $points, $reject ? 'warning' : 'info');
                return ['action' => $reject ? 'reject' : 'probe', 'reason' => $type];
            }
        }
        return ['action' => 'allow', 'reason' => ''];
    }

    /** Ereignis speichern und Punkte zählen; bei Überschreitung automatisch sperren. */
    public function record(string $type, Request $req, int $points = 1, string $severity = 'warning', string $detail = ''): void
    {
        $ip = $req->ip();
        $hash = $this->ipHash($ip);
        $this->db->insert('security_events', [
            'event_type' => $type, 'severity' => $severity, 'ip_hash' => $hash, 'ip_masked' => IpAddress::mask($ip),
            'method' => mb_substr($req->method, 0, 10), 'path' => mb_substr(self::cleanPath($req->path), 0, 255),
            'points' => max(0, min(255, $points)), 'detail' => $detail !== '' ? mb_substr($detail, 0, 255) : null, 'created_at' => $this->now(),
        ]);
        if ($points <= 0 || $this->isAllowed($ip)) {
            return;
        }
        $since = $this->clock->now()->modify('-' . self::WINDOW_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $score = (int) $this->db->value('SELECT COALESCE(SUM(points), 0) FROM security_events WHERE ip_hash = ? AND created_at >= ?', [$hash, $since]);
        if ($score >= self::BAN_THRESHOLD && !$this->isBlocked($ip)) {
            $this->autoBan($ip, $type);
        }
    }

    private function autoBan(string $ip, string $type): void
    {
        $hash = $this->ipHash($ip);
        if (IpAddress::inCidr($ip, '127.0.0.0/8') || $ip === '::1') {
            // Lokale Adresse: Server-Proxy reicht die Besucher-IP nicht durch. Eine Sperre träfe alle Besucher.
            $this->db->insert('security_events', [
                'event_type' => 'proxy_ip', 'severity' => 'critical', 'points' => 0, 'ip_hash' => $hash, 'ip_masked' => IpAddress::mask($ip),
                'detail' => 'Keine Sperre: Anfragen kommen von einer lokalen Adresse. Weitergabe der Besucher-IP am Server prüfen (docs/deployment.md).',
                'created_at' => $this->now(),
            ]);
            return;
        }
        $weekAgo = $this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s');
        $repeat = $this->db->value("SELECT 1 FROM ip_rules WHERE ip_hash = ? AND source = 'auto' AND created_at >= ? LIMIT 1", [$hash, $weekAgo]) !== null;
        $until = $this->clock->now()->modify($repeat ? '+' . self::REPEAT_BAN_HOURS . ' hours' : '+' . self::BAN_MINUTES . ' minutes');
        $this->db->insert('ip_rules', [
            'rule' => 'block', 'ip_hash' => $hash, 'ip_masked' => IpAddress::mask($ip), 'source' => 'auto',
            'reason' => 'Automatisch: ' . $type . ($repeat ? ' (wiederholt)' : ''), 'expires_at' => $until->format('Y-m-d H:i:s'),
            'created_at' => $this->now(),
        ]);
        $this->db->insert('security_events', [
            'event_type' => 'auto_ban', 'severity' => 'critical', 'ip_hash' => $hash, 'ip_masked' => IpAddress::mask($ip),
            'detail' => 'Gesperrt bis ' . $until->format('Y-m-d H:i') . ' UTC', 'created_at' => $this->now(),
        ]);
    }

    public function isBlocked(string $ip): bool
    {
        $hash = $this->ipHash($ip);
        if ($this->db->value("SELECT 1 FROM ip_rules WHERE rule = 'block' AND ip_hash = ? AND (expires_at IS NULL OR expires_at > ?) LIMIT 1", [$hash, $this->now()]) !== null) {
            return true;
        }
        foreach ($this->manualRanges('block') as $cidr) {
            if (IpAddress::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public function isAllowed(string $ip): bool
    {
        foreach ($this->manualRanges('allow') as $cidr) {
            if (IpAddress::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /** @var array<string, list<string>>|null */
    private ?array $ranges = null;

    /** @return list<string> */
    private function manualRanges(string $rule): array
    {
        if ($this->ranges === null) {
            $this->ranges = ['block' => [], 'allow' => []];
            foreach ($this->db->all('SELECT rule, cidr FROM ip_rules WHERE cidr IS NOT NULL AND (expires_at IS NULL OR expires_at > ?)', [$this->now()]) as $r) {
                $this->ranges[$r['rule']][] = (string) $r['cidr'];
            }
        }
        return $this->ranges[$rule];
    }

    public function ipHash(string $ip): string
    {
        return $this->crypto->hash($ip, 'ip');
    }

    /** Pfad ohne Steuerzeichen; Abfrageparameter werden nie gespeichert (können Daten enthalten). */
    private static function cleanPath(string $path): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', $path) ?? '';
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
