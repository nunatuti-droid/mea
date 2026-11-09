<?php
declare(strict_types=1);

namespace Mea\Module\Applications;

use Mea\Core\Csrf;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\Session;
use Mea\Core\View;
use Mea\Module\Content\ContentRepository;
use Mea\Module\Inquiries\RateLimiter;

/** Öffentliches Bewerbungsformular mit Upload der Unterlagen (PDF). */
final class ApplicationController
{
    private const URL = '/karriere/bewerbung/';

    /** @param \Closure(string):bool $isUploaded prüft, ob eine Datei wirklich per HTTP hochgeladen wurde */
    public function __construct(
        private View $view,
        private ApplicationService $service,
        private RateLimiter $limiter,
        private ContentRepository $content,
        private \Closure $isUploaded,
        private ?\Mea\Module\Mail\Mailer $mailer = null,
        private \Psr\Log\LoggerInterface|null $log = null,
    ) {}

    public function show(Request $req): Response
    {
        Session::start($req->isSecure());
        $pos = $req->input('stelle');
        return $this->render(['values' => ['position' => isset(ApplicationService::POSITIONS[$pos]) ? $pos : ''],
            'sent' => Session::flash('application_sent') === true]);
    }

    public function submit(Request $req): Response
    {
        Session::start($req->isSecure());
        // Zu große Uploads: PHP verwirft dann das ganze Formular
        if ($req->post === [] && (int) ($req->server['CONTENT_LENGTH'] ?? 0) > 0) {
            return $this->render(['values' => [], 'form_error' => 'Ihre Dateien sind zusammen zu groß. Bitte laden Sie höchstens drei PDF-Dateien mit je bis zu 5 MB hoch.'], 413);
        }
        $in = [];
        foreach (['position', 'first_name', 'last_name', 'email', 'phone', 'message', 'privacy'] as $k) {
            $in[$k] = trim($req->input($k));
        }
        if (!Csrf::check($req->input('_csrf'))) {
            return $this->render(['values' => $in, 'form_error' => 'Ihre Sitzung ist abgelaufen. Bitte senden Sie das Formular erneut.'], 400);
        }
        $started = (int) $req->input('_t');
        if ($req->input('website') !== '' || $started === 0 || time() - $started < 3) {
            Session::flash('application_sent', true);
            return Response::redirect(self::URL . '?gesendet=1#formular', 303);
        }
        if (!$this->limiter->hit('application', $req->ip(), 5, 3600)) {
            return $this->render(['values' => $in, 'form_error' => 'Sie haben in kurzer Zeit viele Bewerbungen gesendet. Bitte versuchen Sie es später erneut oder rufen Sie uns an.'], 429);
        }
        $files = ApplicationService::normalizeFiles($req->files['documents'] ?? null);
        foreach ($files as $i => $f) {
            if ($f['error'] === UPLOAD_ERR_OK && !($this->isUploaded)($f['tmp'])) {
                $files[$i]['error'] = UPLOAD_ERR_PARTIAL;
            }
        }
        $errors = $this->service->validate($in, $files);
        if ($errors !== []) {
            return $this->render(['values' => $in, 'errors' => $errors], 422);
        }
        try {
            $id = $this->service->store($in, $files);
        } catch (\Throwable $e) {
            $this->log?->error('Bewerbung konnte nicht gespeichert werden', ['error' => $e->getMessage()]);
            return $this->render(['values' => $in, 'form_error' => 'Ihre Bewerbung konnte gerade nicht gespeichert werden. Bitte versuchen Sie es später erneut oder rufen Sie uns an.'], 500);
        }
        $this->notifyOffice($id, $in, count($files));
        Session::flash('application_sent', true);
        return Response::redirect(self::URL . '?gesendet=1#formular', 303);
    }

    /** @param array<string,string> $in */
    private function notifyOffice(int $id, array $in, int $fileCount): void
    {
        if ($this->mailer === null || ($office = $this->mailer->officeAddress()) === null) {
            return;
        }
        try {
            // Keine Anhänge und keine Unterlagen per E-Mail: die Unterlagen liegen nur in der Verwaltung
            $this->mailer->queue('application_notification', $office, null, ['bewerbung' => [
                'name' => $in['first_name'] . ' ' . $in['last_name'],
                'stelle' => ApplicationService::POSITIONS[$in['position']] ?? '',
                'dateien' => (string) $fileCount,
            ]], 'application-' . $id, 'application', $id);
        } catch (\Throwable) {
            // Bewerbung ist gespeichert und in der Verwaltung sichtbar
        }
    }

    /** @param array<string,mixed> $data */
    private function render(array $data, int $status = 200): Response
    {
        return Response::html($this->view->render('public/application.twig', $data + [
            'page' => $this->content->publishedPage('karriere'),
            'positions' => ApplicationService::POSITIONS,
            'max_files' => ApplicationService::MAX_FILES,
            'csrf' => Csrf::token(),
            'form_started' => time(),
            'errors' => [],
            'form_error' => null,
            'sent' => false,
        ]), $status)->withHeader('Cache-Control', 'no-store');
    }
}
