<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\Csrf;
use Mea\Module\Security\Firewall;

final class FirewallTest extends IntegrationTestCase
{
    private function from(string $ip, string $uri): \Mea\Core\Response
    {
        return $this->request('GET', $uri, [], ['REMOTE_ADDR' => $ip]);
    }

    public function testAttackPatternsAreRejectedAndLogged(): void
    {
        self::assertSame(403, $this->from('198.51.100.1', '/telc-pruefungszentrum/?niveau=1%27%20union%20select%20password%20from%20users--')->status);
        self::assertSame(403, $this->from('198.51.100.2', '/standorte/..%2f..%2fetc%2fpasswd')->status);
        self::assertSame(403, $this->from('198.51.100.3', '/kontakt/?thema=%3Cscript%3Ealert(1)%3C/script%3E')->status);
        self::assertSame(404, $this->from('198.51.100.4', '/wp-login.php')->status, 'Scanner bekommen nur 404');
        $events = self::db()->all('SELECT event_type, ip_masked, path FROM security_events ORDER BY id');
        self::assertSame(['sql_injection', 'path_traversal', 'xss_attempt', 'probe'], array_column($events, 'event_type'));
        self::assertSame('198.51.100.x', $events[0]['ip_masked'], 'IP nur gekürzt');
        self::assertStringNotContainsString('password', implode(' ', array_column($events, 'path')), 'Abfrageparameter werden nicht gespeichert');
    }

    public function testNormalRequestsPass(): void
    {
        foreach (['/', '/telc-pruefungszentrum/?niveau=B1', '/kontakt/?thema=pruefung', '/faq/', '/preise/'] as $u) {
            self::assertSame(200, $this->from('198.51.100.9', $u)->status, $u);
        }
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM security_events'));
        // Formulartext darf „verdächtige“ Wörter enthalten (der Inhalt wird beim Anzeigen maskiert)
        $r = $this->request('POST', '/kontakt/', ['_csrf' => Csrf::token(), '_t' => (string) (time() - 30), 'website' => '', 'name' => 'Lea Test',
            'email' => 'lea@example.org', 'message' => 'Frage: Wie wähle ich (select) den richtigen Termin aus?', 'privacy' => '1'], ['REMOTE_ADDR' => '198.51.100.9']);
        self::assertSame(303, $r->status);
    }

    public function testRepeatedAttacksLeadToTemporaryBan(): void
    {
        $ip = '198.51.100.20';
        $this->from($ip, '/?q=union%20select%201');
        $this->from($ip, '/?q=union%20select%202');
        self::assertSame(403, $this->from($ip, '/')->status, 'nach 10 Punkten gesperrt');
        $ban = self::db()->one("SELECT * FROM ip_rules WHERE source = 'auto'");
        self::assertNotNull($ban);
        self::assertNull($ban['cidr'], 'gespeichert wird nur der Hash');
        self::assertSame(200, $this->from('198.51.100.21', '/')->status, 'andere Besucher nicht betroffen');
        // Sperre läuft ab
        self::db()->run('UPDATE ip_rules SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        self::assertSame(200, $this->from($ip, '/')->status);
    }

    public function testAllowlistAndManualBlock(): void
    {
        self::db()->insert('ip_rules', ['rule' => 'allow', 'cidr' => '192.0.2.0/24', 'reason' => 'Büro', 'source' => 'manual']);
        self::db()->insert('ip_rules', ['rule' => 'block', 'cidr' => '203.0.113.0/24', 'reason' => 'Test', 'source' => 'manual']);
        for ($i = 0; $i < 5; $i++) {
            $this->from('192.0.2.5', '/?q=union%20select%201');
        }
        self::assertSame(200, $this->from('192.0.2.5', '/')->status, 'Freigabeliste wird nie gesperrt');
        self::assertSame(403, $this->from('203.0.113.99', '/')->status);
    }

    public function testFailedLoginsCountTowardsBan(): void
    {
        $ip = '198.51.100.30';
        for ($i = 0; $i < Firewall::BAN_THRESHOLD; $i++) {
            $this->request('POST', '/verwaltung/anmelden/', ['_csrf' => Csrf::token(), 'email' => "x$i@example.org", 'password' => 'falsch', 'weiter' => ''], ['REMOTE_ADDR' => $ip]);
        }
        self::assertSame(403, $this->from($ip, '/verwaltung/anmelden/')->status);
    }

    public function testAdminCannotBlockOwnAddress(): void
    {
        $this->loginAs('admin');
        $r = $this->adminPost('/verwaltung/sicherheit/regeln/', ['rule' => 'block', 'cidr' => '203.0.113.0/24', 'reason' => 'Test', 'days' => '']);
        self::assertSame(422, $r->status, 'eigene Adresse 203.0.113.7 liegt im Bereich');
        $r = $this->adminPost('/verwaltung/sicherheit/regeln/', ['rule' => 'block', 'cidr' => '198.51.100.0/24', 'reason' => 'Angriffe', 'days' => '7']);
        self::assertSame(303, $r->status);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM ip_rules WHERE cidr = '198.51.100.0/24'"));
    }

    public function testMaintenanceModeKeepsAdminReachable(): void
    {
        $flag = \Mea\Core\App::maintenanceFlag(self::root());
        file_put_contents($flag, 'test');
        try {
            self::assertSame(503, $this->get('/')->status);
            self::assertSame(200, $this->get('/verwaltung/anmelden/')->status);
        } finally {
            unlink($flag);
        }
        self::assertSame(200, $this->get('/')->status);
    }

    public function testScannerToolsAndBadMethodsAreRejected(): void
    {
        self::assertSame(403, $this->request('GET', '/', [], ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_USER_AGENT' => 'sqlmap/1.7.2#stable'])->status);
        self::assertSame(405, $this->request('TRACE', '/', [], ['REMOTE_ADDR' => '198.51.100.21'])->status);
        self::assertSame(200, $this->request('GET', '/', [], ['REMOTE_ADDR' => '198.51.100.22', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->status);
        self::assertSame(['bad_bot', 'bad_method'], array_column(self::db()->all('SELECT event_type FROM security_events ORDER BY id'), 'event_type'));
    }

    public function testLoginHoneypotAndRepeatedFailuresBanTheConnection(): void
    {
        $ip = '198.51.100.30';
        $r = $this->request('POST', '/verwaltung/anmelden/', ['_csrf' => Csrf::token(), 'email' => 'x@example.org', 'password' => 'x', 'website' => 'spam'], ['REMOTE_ADDR' => $ip]);
        self::assertSame(422, $r->status);
        self::assertSame('bot_login', self::db()->value('SELECT event_type FROM security_events ORDER BY id DESC LIMIT 1'));
        for ($i = 0; $i < 5; $i++) {
            $this->request('POST', '/verwaltung/anmelden/', ['_csrf' => Csrf::token(), 'email' => 'x@example.org', 'password' => 'falsch', 'website' => ''], ['REMOTE_ADDR' => $ip]);
        }
        self::assertSame(403, $this->from($ip, '/')->status, 'Bot-Versuch plus Fehlversuche führen zur Sperre');
    }

    public function testAdminAccessCanBeRestrictedWithoutSelfLockout(): void
    {
        $this->loginAs('admin');
        // eigene Adresse fehlt: wird abgelehnt
        $r = $this->adminPost('/verwaltung/sicherheit/verwaltungszugang/', ['admin_ranges' => '192.0.2.0/24']);
        self::assertSame(422, $r->status);
        self::assertSame([], $this->app->firewall->adminRanges());
        $r = $this->adminPost('/verwaltung/sicherheit/verwaltungszugang/', ['admin_ranges' => "203.0.113.0/24\n192.0.2.5"]);
        self::assertSame(303, $r->status);
        self::assertSame(['203.0.113.0/24', '192.0.2.5'], $this->app->firewall->adminRanges());
        self::assertSame(404, $this->from('198.51.100.40', '/verwaltung/anmelden/')->status, 'fremde Adresse sieht keine Verwaltung');
        self::assertSame(200, $this->from('198.51.100.40', '/')->status, 'Website bleibt erreichbar');
        self::assertNotSame(404, $this->from('192.0.2.5', '/verwaltung/anmelden/')->status);
        $this->adminPost('/verwaltung/sicherheit/verwaltungszugang/', ['admin_ranges' => '']);
        self::assertSame([], $this->app->firewall->adminRanges());
    }
}
