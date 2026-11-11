<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

final class HttpTest extends IntegrationTestCase
{
    /** @return iterable<string,array{string}> */
    public static function publicPages(): iterable
    {
        foreach (['/', '/campus/', '/faq/', '/pruefungen/', '/pruefungen/preise/', '/standorte/', '/kontakt/',
            '/ueber-uns/', '/impressum/', '/datenschutz/', '/coaching-beratung/', '/coaching-beratung/mea-plus/', '/standorte/marl/'] as $p) {
            yield $p => [$p];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('publicPages')]
    public function testPublicPageRendersWithSecurityHeaders(string $path): void
    {
        $r = $this->get($path);
        self::assertSame(200, $r->status, $path);
        self::assertStringContainsString('<meta name="description"', $r->body, "$path ohne Meta-Beschreibung");
        self::assertStringNotContainsString('info@meabildungszentrum.de', $r->body, 'E-Mail darf nicht im Klartext stehen');
        self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertSame('DENY', $r->headers['X-Frame-Options']);
        self::assertSame(1, preg_match_all('/<h1[\s>]/', $r->body), "$path braucht genau eine h1");
    }

    public function testHstsOnlyOnHttps(): void
    {
        self::assertArrayNotHasKey('Strict-Transport-Security', $this->get('/')->headers);
        $r = $this->request('GET', '/', [], ['HTTPS' => 'on']);
        self::assertArrayHasKey('Strict-Transport-Security', $r->headers);
    }

    public function testTrailingSlashRedirect(): void
    {
        $r = $this->get('/kontakt?thema=avgs');
        self::assertSame(301, $r->status);
        self::assertSame('/kontakt/?thema=avgs', $r->headers['Location']);
    }

    public function testRedirectsFromAdminWork(): void
    {
        // Weiterleitungen legt die Verwaltung an; die Startdaten enthalten keine mehr
        self::db()->run("INSERT INTO redirects (source_path, target_url, status_code) VALUES ('/test-alt/', '/kontakt/', 301), ('/test-weg/', NULL, 410)");
        $r = $this->get('/test-alt/');
        self::assertSame(301, $r->status);
        self::assertSame('/kontakt/', $r->headers['Location']);
        self::assertSame(410, $this->get('/test-weg/')->status);
        self::assertSame(404, $this->get('/gibt-es-nicht/')->status);
        self::db()->run("DELETE FROM redirects WHERE source_path IN ('/test-alt/', '/test-weg/')");
    }

    public function testNoindexInPreview(): void
    {
        self::assertSame('noindex, nofollow', $this->get('/')->headers['X-Robots-Tag']);
        self::assertStringContainsString('Disallow: /', $this->get('/robots.txt')->body);
    }

    public function testSitemapIsValidXml(): void
    {
        $r = $this->get('/sitemap.xml');
        self::assertSame(200, $r->status);
        $xml = simplexml_load_string($r->body);
        self::assertNotFalse($xml);
        self::assertGreaterThan(10, count($xml->url));
    }

    public function testCampusStructure(): void
    {
        // neue Hub-Adressen, Onboarding unter Recruiting, Über uns im Footer statt im Hauptmenü
        foreach (['/bildungszentrum/', '/recruiting/', '/recruiting/onboarding/', '/coaching-beratung/', '/coaching-beratung/job-mentoring/', '/sprache-beruf/'] as $p) {
            self::assertSame(200, $this->get($p)->status, $p);
        }
        // alte Adressen gibt es nicht mehr, auch keine Weiterleitung
        foreach (['/leistungen/', '/avgs/', '/fachkraeftegewinnung/', '/sprachkurse/', '/telc-pruefungszentrum/', '/preise/', '/pruefungsvorbereitung/'] as $p) {
            self::assertSame(404, $this->get($p)->status, $p);
        }
        $home = $this->get('/')->body;
        self::assertStringContainsString('Ein Campus. Vier Wege nach vorn.', $home);
        self::assertStringContainsString('href="/recruiting/"', $home);
        self::assertMatchesRegularExpression('#<nav class="nav".*</nav>#s', $home);
        preg_match('#<nav class="nav".*?</nav>#s', $home, $nav);
        self::assertStringNotContainsString('/ueber-uns/', $nav[0], 'Über uns nicht im Hauptmenü');
        preg_match('#<footer.*</footer>#s', $home, $foot);
        self::assertStringContainsString('/ueber-uns/', $foot[0]);
        self::assertSame(0, (int) self::db()->value("SELECT COUNT(*) FROM redirects WHERE source_path IN ('/telc/', '/faqs/', '/courses/')"), 'WordPress-Weiterleitungen entfernt');
        // Prüfungsvorbereitung ist ein Entwurf: erst nach Veröffentlichung erreichbar
        self::assertSame(404, $this->get('/pruefungen/vorbereitung/')->status);
        self::db()->run("UPDATE pages SET status = 'published', blocks_json = draft_json WHERE slug = 'pruefungsvorbereitung'");
        $r = $this->get('/pruefungen/vorbereitung/');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Prüfungsvorbereitung für telc-Prüfungen', $r->body);
        self::assertStringContainsString('/pruefungen/vorbereitung/', $this->get('/sitemap.xml')->body);
        self::db()->run("UPDATE pages SET status = 'draft', blocks_json = '[]' WHERE slug = 'pruefungsvorbereitung'");
    }
}
