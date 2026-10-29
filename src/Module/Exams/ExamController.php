<?php
declare(strict_types=1);

namespace Mea\Module\Exams;

use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\View;
use Mea\Module\Content\ContentRepository;

/** Ein gemeinsames Template für jede Prüfungsart – keine Datei pro Prüfung. */
final class ExamController
{
    public function __construct(
        private View $view,
        private ExamRepository $exams,
        private ContentRepository $content,
    ) {}

    public function index(Request $req): Response
    {
        $filter = ['provider' => $req->input('anbieter'), 'level' => $req->input('niveau')];
        return Response::html($this->view->render('public/exams_index.twig', [
            'page' => $this->content->publishedPage('telc-pruefungszentrum'),
            'exams' => $this->exams->listed($filter),
            'filter' => $filter,
            'options' => $this->exams->filterOptions(),
            'faqs' => $this->content->faqsFor('faq_page'),
            'is_filtered' => $filter['provider'] !== '' || $filter['level'] !== '',
        ]));
    }

    public function show(Request $req, string $slug): Response
    {
        $exam = $this->exams->bySlug($slug);
        if ($exam === null) {
            if ($this->exams->isArchived($slug)) {
                return Response::redirect('/telc-pruefungszentrum/', 301);
            }
            throw HttpException::notFound();
        }
        $others = array_values(array_filter($this->exams->listed(), fn($e) => (int) $e['id'] !== (int) $exam['id']));
        return Response::html($this->view->render('public/exam_show.twig', [
            'exam' => $exam,
            'faqs' => $this->content->faqsFor('exam_type', (int) $exam['id']),
            'others' => array_slice($others, 0, 3),
        ]));
    }

    public function prices(Request $req): Response
    {
        return Response::html($this->view->render('public/prices.twig', [
            'page' => $this->content->publishedPage('preise'),
            'exams' => $this->exams->listed(),
        ]));
    }

    /** Platzhalter bis Phase 4: zeigt ehrlich, dass die Online-Buchung noch nicht aktiv ist. */
    public function book(Request $req, string $slug, string $id): Response
    {
        $exam = $this->exams->bySlug($slug);
        if ($exam === null) {
            throw HttpException::notFound();
        }
        $session = null;
        foreach ($exam['sessions'] as $s) {
            if ((int) $s['id'] === (int) $id) {
                $session = $s;
            }
        }
        if ($session === null) {
            throw HttpException::notFound();
        }
        if (!$session['bookable']) {
            // ausgebucht, Frist vorbei oder Prüfung deaktiviert: zurück zur Terminliste
            return Response::redirect('/pruefungen/' . $exam['slug'] . '/#termine', 303);
        }
        return Response::html($this->view->render('public/booking_pending.twig', [
            'exam' => $exam,
            'session' => $session,
        ]))->withHeader('X-Robots-Tag', 'noindex');
    }
}
