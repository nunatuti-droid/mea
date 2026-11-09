<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

final class HttpTest extends IntegrationTestCase
{
    /** @return iterable<string,array{string}> */
    public static function publicPages(): iterable
    {
        foreach (['/', '/leistungen/', '/faq/', '/telc-pruefungszentrum/', '/preise/', '/standorte/', '/kontakt/',
            '/ueber-uns/', '/impressum/', '/datenschutz/', '/avgs/', '/avgs/mea-plus/', '/standorte/marl/'] as $p) {
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

    public function testOldUrlsRedirectOrAreGone(): void
    {
        $permanent = self::firstRedirect(301);
        $r = $this->get($permanent['source_path']);
        self::assertSame(301, $r->status);
        self::assertSame($permanent['target_url'], $r->headers['Location']);

        $gone = self::firstRedirect(410);
        self::assertSame(410, $this->get($gone['source_path'])->status);
        self::assertSame(404, $this->get('/gibt-es-nicht/')->status);
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

    /** @return array<string,mixed> */
    private static function firstRedirect(int $code): array
    {
        $row = self::db()->one('SELECT source_path, target_url FROM redirects WHERE status_code = ? AND source_path NOT LIKE "%*" ORDER BY id LIMIT 1', [$code]);
        self::assertNotNull($row);
        return $row;
    }
}
