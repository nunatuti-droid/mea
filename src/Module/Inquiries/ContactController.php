<?php
declare(strict_types=1);

namespace Mea\Module\Inquiries;

use Mea\Core\Csrf;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\Session;
use Mea\Core\View;
use Mea\Module\Content\ContentRepository;
use Mea\Module\Locations\LocationRepository;

final class ContactController
{
    public function __construct(
        private View $view,
        private InquiryService $service,
        private RateLimiter $limiter,
        private LocationRepository $locations,
        private ContentRepository $content,
    ) {}

    public function show(Request $req): Response
    {
        Session::start($req->isSecure());
        $prefill = ['topic' => $req->input('thema'), 'message' => ''];
        if ($req->input('pruefung') !== '') {
            $prefill['topic'] = 'pruefung';
            $prefill['message'] = 'Ich interessiere mich für die Prüfung „' . mb_substr($req->input('pruefung'), 0, 120) . '“.';
        }
        return $this->render($req, ['values' => $prefill, 'sent' => Session::flash('contact_sent') === true]);
    }

    public function submit(Request $req): Response
    {
        Session::start($req->isSecure());
        $in = [];
        foreach (['name', 'email', 'phone', 'topic', 'location', 'message', 'privacy'] as $k) {
            $in[$k] = $req->input($k);
        }
        if (!Csrf::check($req->input('_csrf'))) {
            return $this->render($req, ['values' => $in, 'form_error' => 'Ihre Sitzung ist abgelaufen. Bitte senden Sie das Formular erneut.'], 400);
        }
        // Spam-Schutz: verstecktes Feld und Mindestzeit zum Ausfüllen
        $started = (int) $req->input('_t');
        if ($req->input('website') !== '' || $started === 0 || time() - $started < 3) {
            Session::flash('contact_sent', true);
            return Response::redirect('/kontakt/?gesendet=1#formular', 303);
        }
        if (!$this->limiter->hit('contact', $req->ip(), 5, 3600)) {
            return $this->render($req, ['values' => $in, 'form_error' => 'Sie haben in kurzer Zeit viele Nachrichten gesendet. Bitte versuchen Sie es später erneut oder rufen Sie uns an.'], 429);
        }
        $ids = array_map(fn($l) => (int) $l['id'], $this->locations->active());
        $errors = $this->service->validate($in, $ids);
        if ($errors !== []) {
            return $this->render($req, ['values' => $in, 'errors' => $errors], 422);
        }
        $this->service->store($in);
        Session::flash('contact_sent', true);
        return Response::redirect('/kontakt/?gesendet=1#formular', 303);
    }

    /** @param array<string,mixed> $data */
    private function render(Request $req, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render('public/contact.twig', $data + [
            'page' => $this->content->publishedPage('kontakt'),
            'locations' => $this->locations->active(),
            'topics' => InquiryService::TOPICS,
            'csrf' => Csrf::token(),
            'form_started' => time(),
            'errors' => [],
            'form_error' => null,
            'sent' => false,
        ]), $status)->withHeader('Cache-Control', 'no-store');
    }
}
