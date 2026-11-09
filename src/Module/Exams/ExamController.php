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
        /** @var \Closure(string, array<string,mixed>, int):?Response Seite aus dem Seitenbaukasten */
        private \Closure $page,
    ) {}

    public function index(Request $req): Response
    {
        return ($this->page)('telc-pruefungszentrum', ['query' => $req->query], 200) ?? throw HttpException::notFound();
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
        return ($this->page)('preise', ['query' => $req->query], 200) ?? throw HttpException::notFound();
    }
}
