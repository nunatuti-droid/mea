<?php
declare(strict_types=1);

namespace Mea\Module\Bookings;

use Mea\Core\App;
use Mea\Core\Csrf;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\Router;
use Mea\Core\Session;
use Mea\Module\Inquiries\RateLimiter;

/** Öffentlicher Buchungsablauf: Formular, Reservierung, Zahlung, Status, Webhook. */
final class BookingController
{
    public function __construct(private App $app, private RateLimiter $limiter) {}

    public function register(Router $r): void
    {
        $r->get('/pruefungen/{slug:[a-z0-9-]+}/buchen/{id:\d+}/', $this->form(...));
        $r->post('/pruefungen/{slug:[a-z0-9-]+}/buchen/{id:\d+}/', $this->submit(...));
        $r->get('/buchung/{ref:MEA-[A-Z0-9]{8}}/', $this->status(...));
        $r->get('/buchung/{ref:MEA-[A-Z0-9]{8}}/zahlung/', $this->paymentReturn(...));
        $r->get('/buchung/{ref:MEA-[A-Z0-9]{8}}/abbruch/', $this->paymentCancel(...));
        $r->post('/buchung/{ref:MEA-[A-Z0-9]{8}}/bezahlen/', $this->retryPayment(...));
        $r->post('/zahlung/paypal/webhook/', $this->webhook(...));
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} */
    private function load(string $slug, string $id): array
    {
        $exam = $this->app->exams->bySlug($slug);
        if ($exam === null) {
            throw HttpException::notFound();
        }
        foreach ($exam['sessions'] as $s) {
            if ((int) $s['id'] === (int) $id) {
                return [$exam, $s];
            }
        }
        throw HttpException::notFound();
    }

    private static function noStore(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex');
    }

    public function form(Request $req, string $slug, string $id, array $extra = [], int $status = 200): Response
    {
        [$exam, $session] = $this->load($slug, $id);
        if (!$session['bookable']) {
            return Response::redirect('/pruefungen/' . $exam['slug'] . '/#termine', 303);
        }
        Session::start($req->isSecure());
        $rule = $this->app->bookings()->rule($exam, $session);
        $form = new BookingForm($this->app->db);
        $agb = $this->app->db->one("SELECT 1 FROM pages WHERE slug = 'agb' AND status = 'published'");
        return self::noStore(Response::html($this->app->view->render('public/booking_form.twig', $extra + [
            'exam' => $exam, 'session' => $session, 'rule' => $rule,
            'required' => BookingForm::requiredStandard($rule),
            'standard' => BookingForm::STANDARD,
            'custom' => $form->customFields((int) $exam['id']),
            'optional_fees' => array_values(array_filter($exam['fees'], fn($f) => (int) $f['is_mandatory'] === 0)),
            'values' => [], 'errors' => [], 'chosen_fees' => [],
            'csrf' => Csrf::token(), 'form_started' => time(),
            'payment_available' => $this->app->paymentGateway()->isConfigured() || ($session['price']['total_cents'] ?? 1) === 0,
            'has_agb' => $agb !== null,
        ]), $status));
    }

    public function submit(Request $req, string $slug, string $id): Response
    {
        Session::start($req->isSecure());
        [$exam, $session] = $this->load($slug, $id);
        if (!Csrf::check($req->input('_csrf'))) {
            return $this->form($req, $slug, $id, ['form_error' => 'Ihre Sitzung ist abgelaufen. Bitte senden Sie das Formular erneut.', 'values' => $req->post], 400);
        }
        if ($req->input('website') !== '' || time() - (int) $req->input('_t') < 4) {
            return $this->form($req, $slug, $id, ['form_error' => 'Bitte prüfen Sie Ihre Angaben und senden Sie das Formular erneut.', 'values' => $req->post], 422);
        }
        if (!$this->limiter->hit('booking', $req->ip(), 10, 3600)) {
            return $this->form($req, $slug, $id, ['form_error' => 'Zu viele Anmeldeversuche in kurzer Zeit. Bitte versuchen Sie es später erneut oder rufen Sie uns an.', 'values' => $req->post], 429);
        }
        $rule = $this->app->bookings()->rule($exam, $session);
        $custom = (new BookingForm($this->app->db))->customFields((int) $exam['id']);
        [$participant, $extra, $errors] = BookingForm::validate($req->post, BookingForm::requiredStandard($rule), $custom);
        $chosen = array_map('intval', $req->inputList('fees'));
        if ($errors !== []) {
            return $this->form($req, $slug, $id, ['errors' => $errors, 'values' => $req->post, 'chosen_fees' => $chosen], 422);
        }
        try {
            $res = $this->app->bookings()->reserve((int) $exam['id'], (int) $session['id'], $participant, $extra, $chosen);
            $url = $this->app->bookings()->startPayment($res['id'], $res['key']);
        } catch (BookingException $e) {
            if (in_array($e->reason, ['full', 'closed', 'disabled', 'not_found'], true)) {
                Session::flash('booking_notice', $e->getMessage());
                return self::noStore(Response::redirect('/pruefungen/' . $exam['slug'] . '/#termine', 303));
            }
            if (isset($res)) {
                // Reservierung besteht, Zahlung konnte nicht starten: Statusseite mit erneutem Versuch
                Session::flash('booking_notice', $e->getMessage());
                return self::noStore(Response::redirect($this->app->bookings()->statusUrl($res['ref'], $res['key']), 303));
            }
            return $this->form($req, $slug, $id, ['form_error' => $e->getMessage(), 'values' => $req->post, 'chosen_fees' => $chosen], 422);
        }
        if ($url === null) {
            return self::noStore(Response::redirect($this->app->bookings()->statusUrl($res['ref'], $res['key']), 303));
        }
        return self::noStore(Response::redirect($url, 303));
    }

    public function status(Request $req, string $ref): Response
    {
        Session::start($req->isSecure());
        $b = $this->app->bookings()->findForCustomer($ref, $req->input('k'));
        if ($b === null) {
            throw HttpException::notFound();
        }
        return self::noStore(Response::html($this->app->view->render('public/booking_status.twig', [
            'b' => $b, 'key' => $req->input('k'), 'notice' => Session::flash('booking_notice'),
            'csrf' => Csrf::token(),
            'can_pay' => in_array($b['status'], ['reserved', 'pending_payment'], true) && $b['payment_status'] !== 'paid'
                && $b['reserved_until'] !== null && $b['reserved_until'] > gmdate('Y-m-d H:i:s'),
        ])));
    }

    public function paymentReturn(Request $req, string $ref): Response
    {
        Session::start($req->isSecure());
        $key = $req->input('k');
        $b = $this->app->bookings()->findForCustomer($ref, $key);
        if ($b === null) {
            throw HttpException::notFound();
        }
        $orderId = $req->input('token');
        if (!preg_match('/^[A-Z0-9]{5,64}$/', $orderId)) {
            Session::flash('booking_notice', 'Die Rückmeldung von PayPal war unvollständig. Ihre Zahlung wird geprüft.');
            return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
        }
        $result = $this->app->bookings()->completeReturn($ref, $orderId);
        Session::flash('booking_notice', match ($result) {
            'confirmed' => null,
            'pending' => 'PayPal prüft Ihre Zahlung noch. Sie erhalten eine E-Mail, sobald sie bestätigt ist.',
            'refunded_full' => 'Ihre Zahlung ist eingegangen, aber der Termin war inzwischen ausgebucht. Der Betrag wurde vollständig erstattet.',
            default => 'Die Zahlung konnte nicht abgeschlossen werden. Sie können es erneut versuchen.',
        });
        return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
    }

    public function paymentCancel(Request $req, string $ref): Response
    {
        Session::start($req->isSecure());
        $key = $req->input('k');
        $b = $this->app->bookings()->findForCustomer($ref, $key);
        if ($b === null) {
            throw HttpException::notFound();
        }
        Session::flash('booking_notice', 'Sie haben die Zahlung abgebrochen. Ihr Platz bleibt noch kurz reserviert; Sie können die Zahlung erneut starten.');
        return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
    }

    public function retryPayment(Request $req, string $ref): Response
    {
        Session::start($req->isSecure());
        $key = $req->input('k');
        $b = $this->app->bookings()->findForCustomer($ref, $key);
        if ($b === null || !Csrf::check($req->input('_csrf'))) {
            throw HttpException::notFound();
        }
        if ($req->input('action') === 'cancel') {
            $this->app->bookings()->cancelUnpaid((int) $b['id'], 'Von der Kundin/dem Kunden abgebrochen');
            Session::flash('booking_notice', 'Die Reservierung wurde aufgehoben.');
            return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
        }
        try {
            $url = $this->app->bookings()->startPayment((int) $b['id'], $key);
        } catch (BookingException $e) {
            Session::flash('booking_notice', $e->getMessage());
            return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
        }
        return self::noStore(Response::redirect($url ?? $this->app->bookings()->statusUrl($ref, $key), 303));
    }

    public function webhook(Request $req): Response
    {
        $raw = $req->rawBody();
        if (strlen($raw) > 200_000) {
            return Response::json(['result' => 'too_large'], 413);
        }
        $headers = [];
        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $h) {
            $headers[$h] = $req->header($h);
        }
        $res = $this->app->bookings()->handleWebhook($headers, $raw);
        $r = Response::json(['result' => $res['result']], $res['status']);
        return $r->withHeader('Cache-Control', 'no-store');
    }
}
