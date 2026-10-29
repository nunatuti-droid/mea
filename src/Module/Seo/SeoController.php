<?php
declare(strict_types=1);

namespace Mea\Module\Seo;

use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Content\ContentRepository;
use Mea\Module\Exams\ExamRepository;
use Mea\Module\Locations\LocationRepository;

/** Sitemap und robots.txt werden aus den veröffentlichten Daten erzeugt. */
final class SeoController
{
    public function __construct(
        private ContentRepository $content,
        private ExamRepository $exams,
        private LocationRepository $locations,
        private string $baseUrl,
        private bool $noindex,
    ) {}

    public function sitemap(Request $req): Response
    {
        $urls = [['/', null], ['/leistungen/', null], ['/telc-pruefungszentrum/', null], ['/preise/', null],
            ['/standorte/', null], ['/kontakt/', null], ['/faq/', null]];
        $fixed = ['leistungen', 'telc-pruefungszentrum', 'preise', 'standorte', 'kontakt', 'faq'];
        foreach ($this->content->publishedPages() as $p) {
            if (!in_array($p['slug'], $fixed, true)) {
                $urls[] = ['/' . $p['slug'] . '/', $p['updated_at']];
            }
        }
        foreach ($this->content->allPublishedServices() as $s) {
            $urls[] = [($s['parent_slug'] ? '/' . $s['parent_slug'] : '') . '/' . $s['slug'] . '/', $s['updated_at']];
        }
        foreach ($this->exams->sitemapEntries() as $e) {
            $urls[] = ['/pruefungen/' . $e['slug'] . '/', $e['updated_at']];
        }
        foreach ($this->locations->active() as $l) {
            $urls[] = ['/standorte/' . $l['slug'] . '/', $l['updated_at']];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$path, $mod]) {
            $xml .= '  <url><loc>' . htmlspecialchars(rtrim($this->baseUrl, '/') . $path, ENT_XML1) . '</loc>';
            if ($mod) {
                $xml .= '<lastmod>' . substr((string) $mod, 0, 10) . '</lastmod>';
            }
            $xml .= "</url>\n";
        }
        return Response::text($xml . '</urlset>', 'application/xml');
    }

    public function robots(Request $req): Response
    {
        if ($this->noindex) {
            return Response::text("User-agent: *\nDisallow: /\n");
        }
        return Response::text("User-agent: *\nDisallow: /admin/\nDisallow: /buchung/\nDisallow: /webhooks/\nDisallow: /pruefungen/*/buchen/\n\nSitemap: "
            . rtrim($this->baseUrl, '/') . "/sitemap.xml\n");
    }
}
