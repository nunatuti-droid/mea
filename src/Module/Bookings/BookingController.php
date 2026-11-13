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
        $r->post('/zahlung/paypal/webhook/', fn(Request $req) => $this->webhook($req, 'paypal'));
        $r->post('/zahlung/stripe/webhook/', fn(Request $req) => $this->webhook($req, 'stripe'));
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
            'payment_available' => $this->app->gateways()->isAvailable() || ($session['price']['total_cents'] ?? 1) === 0,
            'providers' => $this->providers(),
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
        $step = $req->input('step');
        if ($step === 'edit') {
            // Zurück zur Bearbeitung: Eingaben bleiben erhalten
            return $this->form($req, $slug, $id, ['values' => $req->post, 'chosen_fees' => array_map('intval', $req->inputList('fees'))]);
        }
        if ($step !== 'confirm') {
            $rule = $this->app->bookings()->rule($exam, $session);
            $custom = (new BookingForm($this->app->db))->customFields((int) $exam['id']);
            [$participant, $extra, $errors] = BookingForm::validate($req->post, BookingForm::requiredStandard($rule), $custom);
            $chosen = array_map('intval', $req->inputList('fees'));
            if ($errors !== []) {
                return $this->form($req, $slug, $id, ['errors' => $errors, 'values' => $req->post, 'chosen_fees' => $chosen], 422);
            }
            return $this->review($req, $exam, $session, $participant, $extra, $chosen);
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
            $url = $this->app->bookings()->startPayment($res['id'], $res['key'], $req->input('provider'));
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

    /**
     * Zwischenschritt: eingegebene Daten zur Prüfung anzeigen. Erst die Bestätigung
     * (step=confirm) legt die Reservierung an und leitet zum Zahlungsanbieter weiter.
     * @param array<string,mixed> $exam @param array<string,mixed> $session
     * @param array<string,mixed> $p @param array<string,array{label:string,value:mixed}> $extra @param list<int> $chosen
     */
    private function review(Request $req, array $exam, array $session, array $p, array $extra, array $chosen): Response
    {
        $rows = [];
        $name = trim(($p['salutation'] ?? '') . ' ' . $p['first_name'] . ' ' . $p['last_name']);
        $rows[] = ['label' => 'Name', 'value' => $name];
        if ($p['birth_date']) {
            $rows[] = ['label' => BookingForm::STANDARD['birth_date']['label'], 'value' => (new \DateTimeImmutable($p['birth_date']))->format('d.m.Y')];
        }
        if ($p['birth_place']) {
            $rows[] = ['label' => BookingForm::STANDARD['birth_place']['label'], 'value' => $p['birth_place']];
        }
        $rows[] = ['label' => 'E-Mail-Adresse', 'value' => $p['email']];
        if ($p['phone']) {
            $rows[] = ['label' => 'Telefon', 'value' => $p['phone']];
        }
        if ($p['street']) {
            $rows[] = ['label' => 'Anschrift', 'value' => $p['street'] . ', ' . $p['postal_code'] . ' ' . $p['city']];
        }
        foreach ($extra as $x) {
            $v = $x['value'] === true ? 'Ja' : ($x['value'] === false ? 'Nein' : (string) $x['value']);
            if ($v !== '') {
                $rows[] = ['label' => $x['label'], 'value' => $v];
            }
        }
        $fees = array_values(array_filter($exam['fees'], fn($f) => (int) $f['is_mandatory'] === 0 && in_array((int) $f['id'], $chosen, true)));
        $total = ($session['price']['total_cents'] ?? null) === null ? null
            : (int) $session['price']['total_cents'] + array_sum(array_map(fn($f) => (int) $f['amount_cents'], $fees));
        $hidden = [];
        foreach ($req->post as $k => $v) {
            if (is_string($v) && !in_array($k, ['_csrf', 'step'], true)) {
                $hidden[$k] = $v;
            }
        }
        return self::noStore(Response::html($this->app->view->render('public/booking_review.twig', [
            'exam' => $exam, 'session' => $session, 'rows' => $rows, 'fees' => $fees, 'total_cents' => $total,
            'hidden' => $hidden, 'chosen_fees' => $chosen, 'csrf' => Csrf::token(), 'providers' => $this->providers(),
            'rule' => $this->app->bookings()->rule($exam, $session),
        ])));
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
            'csrf' => Csrf::token(), 'providers' => $this->providers(),
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
        // PayPal liefert „token“, Stripe „session_id“
        $orderId = $req->input('session_id') !== '' ? $req->input('session_id') : $req->input('token');
        if (!preg_match('/^[A-Za-z0-9_]{5,120}$/', $orderId)) {
            Session::flash('booking_notice', 'Die Rückmeldung des Zahlungsanbieters war unvollständig. Ihre Zahlung wird geprüft.');
            return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
        }
        $result = $this->app->bookings()->completeReturn($ref, $orderId);
        Session::flash('booking_notice', match ($result) {
            'confirmed' => null,
            'pending' => 'Ihre Zahlung wird noch geprüft (bei SEPA-Lastschrift kann das einige Tage dauern). Sie erhalten eine E-Mail, sobald sie bestätigt ist.',
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
            $url = $this->app->bookings()->startPayment((int) $b['id'], $key, $req->input('provider'));
        } catch (BookingException $e) {
            Session::flash('booking_notice', $e->getMessage());
            return self::noStore(Response::redirect($this->app->bookings()->statusUrl($ref, $key), 303));
        }
        return self::noStore(Response::redirect($url ?? $this->app->bookings()->statusUrl($ref, $key), 303));
    }

    /** Eingerichtete Zahlungsanbieter für die Auswahl: Name und Beschriftung. @return list<array{name:string, label:string}> */
    private function providers(): array
    {
        return array_map(fn($g) => ['name' => $g->name(), 'label' => \Mea\Module\Payments\GatewayRegistry::label($g->name())], $this->app->gateways()->configured());
    }

    public function webhook(Request $req, string $provider = 'paypal'): Response
    {
        $raw = $req->rawBody();
        if (strlen($raw) > 200_000) {
            return Response::json(['result' => 'too_large'], 413);
        }
        $headers = [];
        $names = $provider === 'stripe' ? ['stripe-signature'] : ['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'];
        foreach ($names as $h) {
            $headers[$h] = $req->header($h);
        }
        $res = $this->app->bookings()->handleWebhook($headers, $raw, $provider);
        $r = Response::json(['result' => $res['result']], $res['status']);
        return $r->withHeader('Cache-Control', 'no-store');
    }
}
