<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Cookie-Einwilligung: Banner, Nachweis, nichts Fremdes ohne Eintrag in der Verwaltung. */
final class ConsentTest extends IntegrationTestCase
{
    public function testBannerAndSettingsLinkOnEveryPage(): void
    {
        $body = $this->get('/')->body;
        self::assertStringContainsString('id="consent-banner"', $body);
        self::assertStringContainsString('data-consent-open', $body);
        self::assertStringContainsString('Nur notwendige', $body);
        // Ohne eingetragene Dienste: keine fremden Skripte, keine fremden Quellen in der CSP
        self::assertStringNotContainsString('googletagmanager', $body);
        self::assertStringNotContainsString('googletagmanager', $this->get('/')->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('Statistik', $body);
    }

    public function testConfiguredServicesAppearAsCategoriesAndInCsp(): void
    {
        $before = $this->app->consent->version();
        $this->app->settings->set('consent', ['ga_id' => 'G-ABC1234', 'meta_pixel_id' => '123456789012']);
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        $r = $this->get('/');
        self::assertStringContainsString('Statistik', $r->body);
        self::assertStringContainsString('Marketing', $r->body);
        self::assertStringContainsString('https://www.googletagmanager.com', $r->headers['Content-Security-Policy']);
        self::assertStringContainsString('https://connect.facebook.net', $r->headers['Content-Security-Policy']);
        self::assertStringNotContainsString('<script src="https://www.googletagmanager.com', $r->body, 'Skript erst nach Einwilligung');
        self::assertNotSame($before, $this->app->consent->version(), 'neue Dienste = neue Abfrage');
    }

    public function testChoiceIsLoggedWithoutIp(): void
    {
        $id = str_repeat('ab', 16);
        $r = $this->request('POST', '/einwilligung/', ['id' => $id, 'action' => 'custom', 'categories' => ['media', 'erfunden']], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame(200, $r->status);
        $row = self::db()->one('SELECT * FROM consent_log WHERE consent_id = ?', [$id]);
        self::assertSame('["necessary","media"]', $row['categories_json']);
        self::assertArrayNotHasKey('ip', $row);
        self::assertSame(403, $this->request('POST', '/einwilligung/', ['id' => $id, 'action' => 'custom'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->status);
        self::assertSame(422, $this->request('POST', '/einwilligung/', ['id' => 'x', 'action' => 'custom'])->status);
        $this->loginAs('admin');
        self::assertStringContainsString('Eigene Auswahl', $this->get('/verwaltung/einwilligungen/')->body);
    }
}
