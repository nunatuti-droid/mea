<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Schalter „Website online“ und „Indexierung erlauben“ in der Verwaltung. */
final class SiteStatusTest extends IntegrationTestCase
{
    public function testOfflineShowsMaintenancePageButAdminAndPaymentsStayReachable(): void
    {
        $this->app->siteStatus->save(['online' => false, 'message' => 'Kurze Pause für Umbauten.']);
        foreach (['/', '/kontakt/', '/telc-pruefungszentrum/'] as $p) {
            $r = $this->get($p);
            self::assertSame(503, $r->status, $p);
            self::assertStringContainsString('Kurze Pause für Umbauten.', $r->body);
            self::assertSame('noindex', $r->headers['X-Robots-Tag'] ?? 'noindex', $p);
            self::assertArrayHasKey('Retry-After', $r->headers);
        }
        self::assertNotSame(503, $this->get('/verwaltung/anmelden/')->status);
        self::assertNotSame(503, $this->request('POST', '/zahlung/paypal/webhook/')->status);
        self::assertSame(200, $this->get('/robots.txt')->status);
    }

    public function testAdminSwitchesStatusWithAudit(): void
    {
        $this->loginAs('admin');
        self::assertSame(200, $this->get('/verwaltung/website-status/')->status);

        $r = $this->adminPost('/verwaltung/website-status/', ['indexing' => '1', 'message' => 'Gleich zurück.']);
        self::assertSame(303, $r->status);
        self::assertFalse($this->app->siteStatus->isOnline());
        self::assertSame(503, $this->get('/')->status);
        self::assertStringContainsString('offline', (string) self::db()->value("SELECT summary FROM audit_log WHERE action = 'site.status' ORDER BY id DESC LIMIT 1"));
        self::assertStringContainsString('Die Website ist offline', $this->get('/verwaltung/')->body);

        $this->adminPost('/verwaltung/website-status/', ['online' => '1', 'indexing' => '1', 'message' => '']);
        self::assertTrue($this->app->siteStatus->isOnline());
        self::assertSame(200, $this->get('/')->status);
    }

    public function testIndexingSwitchControlsRobotsAndHeader(): void
    {
        $this->app->siteStatus->save(['indexing' => true]);
        $r = $this->get('/');
        self::assertArrayNotHasKey('X-Robots-Tag', $r->headers);
        self::assertStringNotContainsString('noindex', $r->body);
        $robots = $this->get('/robots.txt')->body;
        self::assertStringContainsString('Disallow: /verwaltung/', $robots);
        self::assertStringNotContainsString("Disallow: /\n", $robots);

        $this->app->siteStatus->save(['indexing' => false]);
        $r = $this->get('/');
        self::assertSame('noindex, nofollow', $r->headers['X-Robots-Tag']);
        self::assertStringContainsString('noindex', $r->body);
        self::assertStringContainsString("Disallow: /\n", $this->get('/robots.txt')->body . "\n");
    }

    public function testEditorWithoutSystemRightCannotSwitch(): void
    {
        $this->loginAs('editor');
        $r = $this->adminPost('/verwaltung/website-status/', []);
        self::assertSame(403, $r->status);
        self::assertTrue($this->app->siteStatus->isOnline());
    }
}
