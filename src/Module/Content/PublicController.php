<?php
declare(strict_types=1);

namespace Mea\Module\Content;

use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\View;
use Mea\Module\Builder\PageService;
use Mea\Module\Builder\PageView;

/**
 * Startseite und freie Seiten. Alle Seiten kommen aus dem Seitenbaukasten (Tabelle pages, Aufbau in blocks_json);
 * Leistungen haben ihr eigenes Template mit Feldern aus der Verwaltung.
 */
final class PublicController
{
    /** @param \Closure():PageService $pages @param \Closure():PageView $pageView */
    public function __construct(
        private View $view,
        private ContentRepository $content,
        private \Closure $pages,
        private \Closure $pageView,
    ) {}

    public function home(Request $req): Response
    {
        $home = ($this->pages)()->home();
        if ($home === null) {
            // Datenbank noch ohne Startseite (z. B. direkt nach den Startdaten): übernehmen und erneut laden
            (new \Mea\Module\Builder\LegacyImport(($this->pages)()->db()))->run();
            $home = ($this->pages)()->home() ?? throw HttpException::notFound();
        }
        return Response::html(($this->pageView)()->html($home, ['query' => $req->query]));
    }

    public function faq(Request $req): Response
    {
        return ($this->pageView)()->respond('faq', ['query' => $req->query]) ?? throw HttpException::notFound();
    }

    /** /{slug}/ – zuerst Leistung, dann Seite. */
    public function slug(Request $req, string $slug): Response
    {
        $service = $this->content->publishedService($slug);
        if ($service !== null) {
            return $this->renderService($service, null);
        }
        // Preise und Prüfungsvorbereitung gehören zu /pruefungen/ und sind nur dort erreichbar
        $page = isset(\Mea\Module\Builder\PageService::NESTED[$slug]) ? null : ($this->pages)()->published($slug);
        if ($page === null) {
            throw HttpException::notFound();
        }
        if ((int) $page['is_home'] === 1) {
            return Response::redirect('/', 301);
        }
        return Response::html(($this->pageView)()->html($page, ['query' => $req->query]));
    }

    /** Vorschau einer Leistung für die Verwaltung. @param array<string,mixed> $row */
    public function serviceHtml(array $row): string
    {
        $service = $this->content->decodeService($row);
        $parent = $row['parent_id'] ? $this->content->serviceById((int) $row['parent_id']) : null;
        return (string) $this->renderService($service, $parent)->body;
    }

    public function childService(Request $req, string $parent, string $slug): Response
    {
        $p = $this->content->publishedService($parent);
        $child = $p !== null ? $this->content->publishedService($slug, (int) $p['id']) : null;
        if ($p !== null && $child !== null) {
            return $this->renderService($child, $p);
        }
        // Unterseite aus dem Seitenbaukasten (Adresse „bereich/seite“)
        $page = isset(\Mea\Module\Builder\PageService::NESTED[$parent . '/' . $slug]) ? null : ($this->pages)()->published($parent . '/' . $slug);
        if ($page === null) {
            throw HttpException::notFound();
        }
        return Response::html(($this->pageView)()->html($page, ['query' => $req->query]));
    }

    /** @param array<string,mixed> $service @param array<string,mixed>|null $parent */
    private function renderService(array $service, ?array $parent): Response
    {
        return Response::html($this->view->render('public/service.twig', [
            'service' => $service,
            'parent' => $parent,
            'children' => $children = $this->content->childServices((int) $service['id']),
            // Coaching-Maßnahmen (mit Kennzahlen) und Informationsseiten (facts.info) werden getrennt gezeigt
            'measures' => array_values(array_filter($children, fn($c) => empty($c['facts']['info']))),
            'topics' => array_values(array_filter($children, fn($c) => !empty($c['facts']['info']))),
            'siblings' => $parent ? array_values(array_filter(
                $this->content->childServices((int) $parent['id']),
                fn($c) => !empty($c['facts']['info']) === !empty($service['facts']['info']),
            )) : [],
            'faqs' => $this->content->faqsFor('service', (int) $service['id']),
            'other_services' => array_values(array_filter(
                $this->content->topServices(),
                fn($s) => (int) $s['id'] !== (int) ($parent['id'] ?? $service['id']),
            )),
        ]));
    }
}
