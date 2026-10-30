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
        $filter = ['provider' => $req->input('anbieter'), 'level' => $req->input('niveau'), 'category' => $req->input('kategorie')];
        return Response::html($this->view->render('public/exams_index.twig', [
            'page' => $this->content->publishedPage('telc-pruefungszentrum'),
            'exams' => $this->exams->listed($filter),
            'filter' => $filter,
            'options' => $this->exams->filterOptions(),
            'categories' => $this->exams->categories(),
            'faqs' => $this->content->faqsFor('faq_page'),
            'is_filtered' => $filter['provider'] !== '' || $filter['level'] !== '' || $filter['category'] !== '',
        ]));
    }

    public function show(Request $req, string $slug): Response
    {
        $exam = $this->exams->bySlug($slug);
        if ($exam === null) {
            if ($this->exams->isArchived($slug)) {
                return Response::redirect('/telc-pruefungszentrum/', 301);
            }
            if ($this->exams->isDisabled($slug)) {
                return Response::redirect('/telc-pruefungszentrum/', 302);
            }
            throw HttpException::notFound();
        }
        $others = array_values(array_filter($this->exams->listed(), fn($e) => (int) $e['id'] !== (int) $exam['id']));
        $notice = null;
        if (isset($req->cookies['mea']) || isset($req->cookies['__Host-mea'])) {
            \Mea\Core\Session::start($req->isSecure());
            $notice = \Mea\Core\Session::flash('booking_notice');
        }
        $resp = Response::html($this->view->render('public/exam_show.twig', [
            'notice' => $notice,
            'exam' => $exam,
            'faqs' => $this->content->faqsFor('exam_type', (int) $exam['id']),
            'others' => array_slice($others, 0, 3),
        ]));
        return $notice !== null ? $resp->withHeader('Cache-Control', 'no-store') : $resp;
    }

    public function prices(Request $req): Response
    {
        return Response::html($this->view->render('public/prices.twig', [
            'page' => $this->content->publishedPage('preise'),
            'exams' => $this->exams->listed(),
        ]));
    }
}
