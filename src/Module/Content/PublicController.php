<?php
declare(strict_types=1);

namespace Mea\Module\Content;

use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\View;
use Mea\Module\Exams\ExamRepository;
use Mea\Module\Locations\LocationRepository;

final class PublicController
{
    public function __construct(
        private View $view,
        private ContentRepository $content,
        private ExamRepository $exams,
        private LocationRepository $locations,
        private SettingsRepository $settings,
    ) {}

    public function home(Request $req): Response
    {
        return Response::html($this->view->render('public/home.twig', [
            'services' => $this->content->topServices(),
            'exams' => $this->exams->listed(),
            'next_sessions' => $this->exams->nextBookableSessions(6),
            'reviews' => $this->content->reviews(),
            'hero_reviews' => $this->content->reviews(true),
            'rating' => $this->content->ratingSummary(),
            'faqs' => $this->content->faqsFor('home'),
            'home' => $this->settings->get('home', []),
            'seo' => $this->settings->get('seo_home', []),
        ]));
    }

    public function servicesIndex(Request $req): Response
    {
        $page = $this->content->publishedPage('leistungen');
        return Response::html($this->view->render('public/services_index.twig', [
            'page' => $page,
            'services' => $this->content->topServices(),
        ]));
    }

    /** /{slug}/ – zuerst Leistung, dann freie Seite. */
    public function slug(Request $req, string $slug): Response
    {
        $service = $this->content->publishedService($slug);
        if ($service !== null) {
            return $this->renderService($service, null);
        }
        $page = $this->content->publishedPage($slug);
        if ($page !== null && !in_array($slug, ['leistungen', 'faq', 'preise', 'kontakt', 'standorte'], true)) {
            $tpl = match ($page['template']) {
                'legal' => 'public/page_legal.twig',
                'about' => 'public/page_about.twig',
                default => 'public/page.twig',
            };
            return Response::html($this->view->render($tpl, [
                'page' => $page,
                'reviews' => $page['template'] === 'about' ? $this->content->reviews() : [],
                'locations' => $page['template'] === 'about' ? $this->locations->active() : [],
            ]));
        }
        throw HttpException::notFound();
    }

    public function childService(Request $req, string $parent, string $slug): Response
    {
        $p = $this->content->publishedService($parent);
        if ($p === null) {
            throw HttpException::notFound();
        }
        $child = $this->content->publishedService($slug, (int) $p['id']);
        if ($child === null) {
            throw HttpException::notFound();
        }
        return $this->renderService($child, $p);
    }

    /** @param array<string,mixed> $service @param array<string,mixed>|null $parent */
    private function renderService(array $service, ?array $parent): Response
    {
        return Response::html($this->view->render('public/service.twig', [
            'service' => $service,
            'parent' => $parent,
            'children' => $this->content->childServices((int) $service['id']),
            'siblings' => $parent ? $this->content->childServices((int) $parent['id']) : [],
            'faqs' => $this->content->faqsFor('service', (int) $service['id']),
            'other_services' => array_values(array_filter(
                $this->content->topServices(),
                fn($s) => (int) $s['id'] !== (int) ($parent['id'] ?? $service['id']),
            )),
        ]));
    }

    public function faq(Request $req): Response
    {
        return Response::html($this->view->render('public/faq.twig', [
            'page' => $this->content->publishedPage('faq'),
            'faqs' => $this->content->faqsFor('faq_page'),
        ]));
    }
}
