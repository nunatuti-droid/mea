<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\Csrf;
use Mea\Core\Response;
use Mea\Tests\Support\FakeGateway;

/**
 * Verbindliche Abnahmetests 7.9 (1–12): Eine neue Prüfungsart entsteht nur über die
 * Verwaltung (HTTP wie im Browser) und funktioniert danach überall – ohne Code-Änderung.
 */
final class AcceptanceTest extends IntegrationTestCase
{
    private FakeGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gw = new FakeGateway();
        $this->app->useGateway($this->gw);
        $this->loginAs('admin');
    }

    /** @param array<string,mixed> $over */
    private function examForm(array $over = []): array
    {
        return $over + [
            'public_name' => 'telc Deutsch Pflege B1-B2', 'internal_name' => 'Pflege B1-B2 (Test)', 'slug' => 'telc-deutsch-pflege-b1-b2',
            'provider_id' => '', 'level_label' => 'B1-B2', 'category' => 'Beruf', 'exam_format' => 'Gesamtprüfung',
            'status' => 'draft', 'is_listed' => '1', 'sort_order' => '50',
            'short_text' => 'Berufsbezogene Sprachprüfung für Pflegekräfte.', 'body_html' => '<p>Prüfung für die <strong>Pflege</strong>.</p><script>alert(1)</script>',
            'target_group_html' => '', 'requirements_html' => '', 'procedure_html' => '', 'duration_text' => 'ca. 3 Stunden',
            'base_price_cents' => '249,00', 'currency' => 'EUR', 'booking_rule_id' => '', 'card_media_id' => '', 'hero_media_id' => '',
            'seo_title' => 'telc Pflege B1-B2 Prüfung in Marl', 'seo_description' => 'Pflegeprüfung B1-B2 beim MEA Bildungszentrum: Termine und Anmeldung.',
        ];
    }

    private function createExamViaAdmin(): int
    {
        $r = $this->adminPost('/verwaltung/pruefungen/neu/', $this->examForm());
        self::assertSame(303, $r->status, substr($r->body, 0, 2000));
        return $this->idFromLocation($r);
    }

    private function publish(int $id, array $over = []): Response
    {
        $version = (string) self::db()->value('SELECT updated_at FROM exam_types WHERE id = ?', [$id]);
        $slug = (string) self::db()->value('SELECT slug FROM exam_types WHERE id = ?', [$id]);
        return $this->adminPost("/verwaltung/pruefungen/$id/", $this->examForm($over + ['status' => 'published', 'slug' => $slug, '_version' => $version, '_action' => 'publish']));
    }

    private function createSessionViaAdmin(int $examId, int $capacity = 10, string $price = '', string $start = '+40 days'): int
    {
        $loc = (int) self::db()->value('SELECT id FROM locations WHERE is_exam_center = 1 ORDER BY id LIMIT 1');
        $local = (new \DateTimeImmutable($start, new \DateTimeZone('Europe/Berlin')))->setTime(9, 0)->format('Y-m-d\TH:i');
        $r = $this->adminPost('/verwaltung/termine/neu/', [
            'exam_type_id' => (string) $examId, 'location_id' => (string) $loc, 'starts_at' => $local, 'ends_at' => '', 'registration_until' => '',
            'capacity' => (string) $capacity, 'price_cents' => $price, 'booking_rule_id' => '', 'status' => 'open', 'is_visible' => '1',
            'notes_public' => 'Bitte Ausweis mitbringen.', 'notes_internal' => '',
        ]);
        self::assertSame(303, $r->status, substr($r->body, 0, 2000));
        return $this->idFromLocation($r);
    }

    /** @return array<string,string> */
    private function bookingData(string $email = 'lea@example.org'): array
    {
        return [
            '_csrf' => Csrf::token(), '_t' => (string) (time() - 30), 'website' => '',
            'salutation' => 'Frau', 'first_name' => 'Lea', 'last_name' => 'Beispiel', 'email' => $email, 'phone' => '0201 123456',
            'birth_date' => '1990-04-12', 'birth_place' => 'Essen', 'street' => 'Musterweg 1', 'postal_code' => '45127', 'city' => 'Essen',
            'terms' => '1', 'privacy' => '1',
        ];
    }

    /** Öffentliches Buchungsformular absenden (Prüfschritt) und bestätigen. @return Response */
    private function submitBooking(string $slug, int $sessionId, string $email = 'lea@example.org'): Response
    {
        $this->get("/pruefungen/$slug/buchen/$sessionId/");
        return $this->request('POST', "/pruefungen/$slug/buchen/$sessionId/", $this->bookingData($email) + ['step' => 'confirm'],
            ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 250)]);
    }

    public function test_ReviewStepShowsDataAndDoesNotReserveOrRedirect(): void
    {
        $examId = $this->createExamViaAdmin();
        $this->publish($examId);
        $slug = (string) self::db()->value('SELECT slug FROM exam_types WHERE id = ?', [$examId]);
        $sid = $this->createSessionViaAdmin($examId, 5);
        $before = (int) self::db()->value('SELECT COUNT(*) FROM bookings');
        $this->get("/pruefungen/$slug/buchen/$sid/");
        $r = $this->request('POST', "/pruefungen/$slug/buchen/$sid/", $this->bookingData('pruef@example.org'), ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame(200, $r->status, substr($r->body, 0, 1500));
        self::assertStringContainsString('Bitte prüfen Sie Ihre Angaben', $r->body);
        self::assertStringContainsString('pruef@example.org', $r->body);
        self::assertStringContainsString('Daten bestätigen', $r->body);
        self::assertSame($before, (int) self::db()->value('SELECT COUNT(*) FROM bookings'), 'noch keine Reservierung');
        self::assertCount(0, $this->gw->orders ?? [], 'noch keine PayPal-Bestellung');
        $edit = $this->request('POST', "/pruefungen/$slug/buchen/$sid/", $this->bookingData('pruef@example.org') + ['step' => 'edit'], ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame(200, $edit->status);
        self::assertStringContainsString('value="pruef@example.org"', $edit->body, 'Eingaben bleiben beim Ändern erhalten');
    }

    /** Buchen und bei PayPal „bezahlen“. @return array<string,mixed> Buchung */
    private function bookAndPay(string $slug, int $sessionId, string $email = 'lea@example.org'): array
    {
        $r = $this->submitBooking($slug, $sessionId, $email);
        self::assertSame(303, $r->status, substr($r->body, 0, 1500));
        self::assertStringStartsWith('https://www.sandbox.paypal.com/', $r->headers['Location']);
        $orderId = array_key_last($this->gw->orders);
        $back = $this->get($this->gw->returnUrlFor($orderId));
        self::assertSame(303, $back->status);
        return self::db()->one('SELECT * FROM bookings WHERE public_ref = ?', [$this->gw->orders[$orderId]['reference']]);
    }

    public function test01_02_ExamIsCreatedOnlyInBackendAndCanBePublished(): void
    {
        $id = $this->createExamViaAdmin();
        $row = self::db()->one('SELECT * FROM exam_types WHERE id = ?', [$id]);
        self::assertSame('draft', $row['status']);
        self::assertSame(24900, (int) $row['base_price_cents']);
        self::assertStringNotContainsString('<script', (string) $row['body_html'], 'HTML wird bereinigt');
        self::assertSame(404, $this->get('/pruefungen/telc-deutsch-pflege-b1-b2/')->status, 'Entwurf nicht öffentlich');

        $r = $this->publish($id);
        self::assertSame(303, $r->status, substr($r->body, 0, 1500));
        self::assertSame('published', self::db()->value('SELECT status FROM exam_types WHERE id = ?', [$id]));
        self::assertGreaterThan(0, (int) self::db()->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'exam_type' AND entity_id = ?", [$id]));
        self::assertGreaterThan(0, (int) self::db()->value("SELECT COUNT(*) FROM content_revisions WHERE entity_type = 'exam_type' AND entity_id = ?", [$id]));
    }

    public function test03_04_05_AppearsInOverviewWithOwnUrlAndSeo(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $overview = $this->get('/pruefungen/');
        self::assertStringContainsString('telc Deutsch Pflege B1-B2', $overview->body);
        self::assertStringContainsString('/pruefungen/telc-deutsch-pflege-b1-b2/', $overview->body);

        $d = $this->get('/pruefungen/telc-deutsch-pflege-b1-b2/');
        self::assertSame(200, $d->status);
        self::assertStringContainsString('<title>telc Pflege B1-B2 Prüfung in Marl</title>', $d->body);
        self::assertStringContainsString('<meta name="description" content="Pflegeprüfung B1-B2 beim MEA Bildungszentrum: Termine und Anmeldung.">', $d->body);
        self::assertStringContainsString('<link rel="canonical" href="https://mea.test/pruefungen/telc-deutsch-pflege-b1-b2/">', $d->body);
        self::assertStringContainsString('/pruefungen/telc-deutsch-pflege-b1-b2/', $this->get('/sitemap.xml')->body);
        self::assertStringContainsString('telc Deutsch Pflege B1-B2', $this->get('/pruefungen/preise/')->body);
    }

    public function test06_PricesAndSessionsAreMaintainedIndependently(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $normal = $this->createSessionViaAdmin($id, 10, '', '+40 days');
        $special = $this->createSessionViaAdmin($id, 10, '199,00', '+60 days');
        $d = $this->get('/pruefungen/telc-deutsch-pflege-b1-b2/')->body;
        self::assertStringContainsString('249,00', $d);
        self::assertStringContainsString('199,00', $d);
        // Grundpreis ändern, Sonderpreis bleibt
        $this->publish($id, ['base_price_cents' => '259,00']);
        self::assertSame(25900, (int) self::db()->value('SELECT base_price_cents FROM exam_types WHERE id = ?', [$id]));
        self::assertSame(19900, (int) self::db()->value('SELECT price_cents FROM exam_sessions WHERE id = ?', [$special]));
        self::assertNull(self::db()->value('SELECT price_cents FROM exam_sessions WHERE id = ?', [$normal]));
        // Anmeldeschluss wird aus der Buchungsregel berechnet
        self::assertNotNull(self::db()->value('SELECT registration_until FROM exam_sessions WHERE id = ?', [$normal]));
    }

    public function test07_08_FullBookingWithPaymentAssigned(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $sid = $this->createSessionViaAdmin($id);
        $b = $this->bookAndPay('telc-deutsch-pflege-b1-b2', $sid);
        self::assertSame('confirmed', $b['status']);
        self::assertSame('paid', $b['payment_status']);
        self::assertSame(24900, (int) $b['total_cents']);
        $p = self::db()->one('SELECT * FROM payments WHERE booking_id = ?', [$b['id']]);
        self::assertSame('captured', $p['status']);
        self::assertSame(24900, (int) $p['amount_cents']);
        self::assertNotEmpty($p['provider_capture_id']);
        $participant = self::db()->one('SELECT * FROM booking_participants WHERE booking_id = ?', [$b['id']]);
        self::assertSame('lea@example.org', $participant['email']);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM email_outbox WHERE template_key = 'booking_confirmation' AND related_id = ?", [$b['id']]));
        // Statusseite nur mit Schlüssel
        self::assertSame(404, $this->get('/buchung/' . $b['public_ref'] . '/?k=falsch')->status);

        // Webhook für dieselbe Zahlung ändert nichts mehr (idempotent)
        $res = $this->app->bookings()->handleWebhook([], json_encode(['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => [
            'id' => $p['provider_capture_id'], 'amount' => ['value' => '249.00', 'currency_code' => 'EUR'],
            'supplementary_data' => ['related_ids' => ['order_id' => $p['provider_order_id']]],
        ]]));
        self::assertSame(200, $res['status']);
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM payments WHERE booking_id = ?', [$b['id']]));
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM email_outbox WHERE template_key = 'booking_confirmation' AND related_id = ?", [$b['id']]));
    }

    public function test08_WrongAmountIsNotConfirmed(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $sid = $this->createSessionViaAdmin($id);
        $this->gw->capturedAmount = 100; // PayPal meldet anderen Betrag
        $b = $this->bookAndPay('telc-deutsch-pflege-b1-b2', $sid);
        self::assertNotSame('confirmed', $b['status']);
    }

    public function test09_CapacityIsEnforced(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $sid = $this->createSessionViaAdmin($id, 1);
        self::assertSame('confirmed', $this->bookAndPay('telc-deutsch-pflege-b1-b2', $sid)['status']);
        $r = $this->submitBooking('telc-deutsch-pflege-b1-b2', $sid, 'zweite@example.org');
        self::assertSame(303, $r->status);
        self::assertStringContainsString('/pruefungen/telc-deutsch-pflege-b1-b2/', $r->headers['Location']);
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM bookings WHERE exam_session_id = ?', [$sid]));
        self::assertStringContainsStringIgnoringCase('ausgebucht', $this->get('/pruefungen/telc-deutsch-pflege-b1-b2/')->body);
    }

    public function test09_ReservationHoldsSeatDuringPayment(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $sid = $this->createSessionViaAdmin($id, 1);
        $this->submitBooking('telc-deutsch-pflege-b1-b2', $sid); // geht zu PayPal, noch nicht bezahlt
        $this->submitBooking('telc-deutsch-pflege-b1-b2', $sid, 'zweite@example.org');
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM bookings WHERE exam_session_id = ?', [$sid]));
    }

    public function test10_DisabledExamCannotBeBooked(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $sid = $this->createSessionViaAdmin($id);
        $this->publish($id, ['status' => 'disabled']);
        self::assertSame(302, $this->get('/pruefungen/telc-deutsch-pflege-b1-b2/')->status, 'Deaktivierte Prüfung ist ausgeblendet');
        self::assertStringNotContainsString('telc-deutsch-pflege-b1-b2', $this->get('/pruefungen/')->body);
        $r = $this->submitBooking('telc-deutsch-pflege-b1-b2', $sid);
        self::assertNotSame(200, $r->status);
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM bookings WHERE exam_session_id = ?', [$sid]));
        self::assertSame([], $this->gw->orders);
    }

    public function test11_ExistingBookingsSurviveChanges(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $sid = $this->createSessionViaAdmin($id);
        $b = $this->bookAndPay('telc-deutsch-pflege-b1-b2', $sid);

        $this->publish($id, ['public_name' => 'telc Pflege (neuer Name)', 'base_price_cents' => '299,00']);
        // Termin verschieben und Plätze ändern
        $s = self::db()->one('SELECT * FROM exam_sessions WHERE id = ?', [$sid]);
        $loc = (int) $s['location_id'];
        $r = $this->adminPost("/verwaltung/termine/$sid/", [
            'exam_type_id' => (string) $id, 'location_id' => (string) $loc,
            'starts_at' => (new \DateTimeImmutable('+45 days', new \DateTimeZone('Europe/Berlin')))->setTime(10, 0)->format('Y-m-d\TH:i'),
            'ends_at' => '', 'registration_until' => '', 'capacity' => '12', 'price_cents' => '', 'booking_rule_id' => '', 'status' => 'open',
            'is_visible' => '1', 'notes_public' => '', 'notes_internal' => '', 'notify_participants' => '1', 'change_note' => 'Neue Uhrzeit 10 Uhr.',
            '_version' => (string) $s['updated_at'],
        ]);
        self::assertSame(303, $r->status, substr($r->body, 0, 1500));

        $after = self::db()->one('SELECT * FROM bookings WHERE id = ?', [$b['id']]);
        self::assertSame('confirmed', $after['status']);
        self::assertSame(24900, (int) $after['total_cents'], 'Preis der Buchung bleibt');
        $snap = json_decode((string) $after['snapshot_json'], true);
        self::assertSame('telc Deutsch Pflege B1-B2', $snap['exam']['public_name'], 'Momentaufnahme bleibt');
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM email_outbox WHERE template_key = 'session_changed' AND related_id = ?", [$b['id']]));
        // Löschen einer Prüfung mit Terminen wird verhindert
        $this->adminPost("/verwaltung/pruefungen/$id/loeschen/", []);
        self::assertNotNull(self::db()->value('SELECT id FROM exam_types WHERE id = ?', [$id]));
    }

    public function test12_SeededExamsStillWork(): void
    {
        $seeded = self::db()->all("SELECT id, slug, public_name FROM exam_types WHERE slug IN ('telc-deutsch-a1','telc-deutsch-a2','telc-deutsch-a2-b1','telc-deutsch-b1','telc-deutsch-b2','telc-deutsch-c1')");
        self::assertGreaterThanOrEqual(5, count($seeded));
        // Startdaten kommen als Entwurf (ohne erfundene Preise); die Verwaltung veröffentlicht sie
        self::db()->run("UPDATE exam_types SET status = 'published' WHERE slug LIKE 'telc-deutsch-%'");
        $overview = $this->get('/pruefungen/')->body;
        foreach ($seeded as $e) {
            self::assertSame(200, $this->get('/pruefungen/' . $e['slug'] . '/')->status, $e['slug']);
            self::assertStringContainsString('/pruefungen/' . $e['slug'] . '/', $overview);
        }
        // Eine vorhandene Prüfung bekommt im Backend Preis und Termin und ist buchbar
        $b1 = (int) self::db()->value("SELECT id FROM exam_types WHERE slug = 'telc-deutsch-b1'");
        self::db()->run('UPDATE exam_types SET base_price_cents = 18000 WHERE id = ?', [$b1]);
        $sid = $this->createSessionViaAdmin($b1);
        self::assertSame('confirmed', $this->bookAndPay('telc-deutsch-b1', $sid)['status']);
    }

    // ---------------------------------------------------------------- Stripe (neben PayPal)

    private const STRIPE_KEY = 'sk_test_abcdefgh12345678';
    private const STRIPE_HOOK = 'whsec_testsecret';

    /** @param list<\GuzzleHttp\Psr7\Response> $responses @param list<array<string,mixed>> $history */
    private function useStripe(array $responses, array &$history): \Mea\Module\Payments\StripeGateway
    {
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $gw = new \Mea\Module\Payments\StripeGateway(new \GuzzleHttp\Client(['handler' => $stack, 'http_errors' => true]), self::STRIPE_KEY, self::STRIPE_HOOK);
        $this->app->useGateway($gw);
        return $gw;
    }

    private static function json(array $data): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], json_encode($data));
    }

    private function stripeHook(array $event, ?string $secret = null): Response
    {
        $body = json_encode($event);
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret ?? self::STRIPE_HOOK);
        return $this->request('POST', '/zahlung/stripe/webhook/', [], ['_RAW_BODY' => $body, 'HTTP_STRIPE_SIGNATURE' => $sig]);
    }

    public function test20_StripeBookingPaymentWebhookAndRefund(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $slug = 'telc-deutsch-pflege-b1-b2';
        $sid = $this->createSessionViaAdmin($id);
        $hist = [];
        $paidSession = fn(string $ref) => self::json([
            'id' => 'cs_test_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a7B8c9', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 24900, 'currency' => 'eur',
            'client_reference_id' => $ref, 'customer_details' => ['email' => 'payer@example.org'],
            'payment_intent' => ['id' => 'pi_1', 'status' => 'succeeded', 'latest_charge' => ['id' => 'ch_test_1']],
        ]);
        $this->useStripe([self::json(['id' => 'cs_test_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a7B8c9', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a7B8c9'])], $hist);

        // Formular zeigt Stripe als Zahlungsweg
        self::assertStringContainsString('Stripe', $this->get("/pruefungen/$slug/buchen/$sid/")->body);

        // Buchung bestätigen: Weiterleitung zu Stripe, Betrag und Rücksprungadresse stimmen
        $r = $this->request('POST', "/pruefungen/$slug/buchen/$sid/", $this->bookingData() + ['step' => 'confirm', 'provider' => 'stripe'], ['REMOTE_ADDR' => '198.51.100.21']);
        self::assertSame(303, $r->status, substr($r->body, 0, 1000));
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a7B8c9', $r->headers['Location']);
        parse_str((string) $hist[0]['request']->getBody(), $form);
        self::assertSame('24900', $form['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('eur', $form['line_items'][0]['price_data']['currency']);
        self::assertStringContainsString('{CHECKOUT_SESSION_ID}', $form['success_url']);
        self::assertSame('Basic ' . base64_encode(self::STRIPE_KEY . ':'), $hist[0]['request']->getHeaderLine('Authorization'));
        $p = self::db()->one("SELECT * FROM payments WHERE provider = 'stripe'");
        self::assertSame('sandbox', $p['mode']);
        $b = self::db()->one('SELECT * FROM bookings WHERE id = ?', [$p['booking_id']]);
        self::assertSame('pending_payment', $b['status']);

        // Rückkehr von Stripe: Zahlung wird serverseitig nachgelesen und bestätigt
        $this->useStripe([$paidSession($b['public_ref'])], $hist);
        $back = (string) preg_replace('#^https?://[^/]+#', '', str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a7B8c9', $form['success_url']));
        self::assertSame(303, $this->get($back)->status);
        $b = self::db()->one('SELECT * FROM bookings WHERE id = ?', [$b['id']]);
        self::assertSame('confirmed', $b['status']);
        self::assertSame('paid', $b['payment_status']);
        $p = self::db()->one('SELECT * FROM payments WHERE id = ?', [$p['id']]);
        self::assertSame('captured', $p['status']);
        self::assertSame('ch_test_1', $p['provider_capture_id']);

        // Webhook: falsche Signatur wird abgelehnt, richtige ist idempotent
        $done = ['id' => 'evt_1', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8s9T0u1V2w3X4y5Z6a7B8c9']]];
        self::assertSame(400, $this->stripeHook($done, 'whsec_falsch')->status);
        self::assertSame(200, $this->stripeHook($done)->status);
        self::assertStringContainsString('duplicate', $this->stripeHook($done)->body);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM email_outbox WHERE template_key = 'booking_confirmation' AND related_id = ?", [$b['id']]));

        // Erstattung im Stripe-Dashboard wird übernommen
        self::assertSame(200, $this->stripeHook(['id' => 'evt_2', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_test_1', 'amount_refunded' => 10000]]])->status);
        $p = self::db()->one('SELECT * FROM payments WHERE id = ?', [$p['id']]);
        self::assertSame(10000, (int) $p['refunded_cents']);
        self::assertSame('partially_refunded', $p['status']);

        // Erstattung aus der Verwaltung läuft über Stripe (Charge) und wird nicht doppelt gezählt
        $hist = [];
        $this->useStripe([self::json(['id' => 're_1', 'status' => 'succeeded'])], $hist);
        self::assertSame(5000, $this->app->bookings()->refund((int) $b['id'], 5000, 'Test', null));
        parse_str((string) $hist[0]['request']->getBody(), $rf);
        self::assertSame('ch_test_1', $rf['charge']);
        self::assertSame('5000', $rf['amount']);
        self::assertSame(200, $this->stripeHook(['id' => 'evt_3', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_test_1', 'amount_refunded' => 15000]]])->status);
        self::assertSame(15000, (int) self::db()->value('SELECT refunded_cents FROM payments WHERE id = ?', [$p['id']]));
    }

    public function test21_StripeSepaPaymentStaysPendingUntilWebhook(): void
    {
        $id = $this->createExamViaAdmin();
        $this->publish($id);
        $slug = 'telc-deutsch-pflege-b1-b2';
        $sid = $this->createSessionViaAdmin($id);
        $hist = [];
        $this->useStripe([self::json(['id' => 'cs_test_sepa1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_sepa1'])], $hist);
        $r = $this->request('POST', "/pruefungen/$slug/buchen/$sid/", $this->bookingData('sepa@example.org') + ['step' => 'confirm'], ['REMOTE_ADDR' => '198.51.100.22']);
        self::assertSame(303, $r->status);
        $p = self::db()->one("SELECT * FROM payments WHERE provider_order_id = 'cs_test_sepa1'");
        self::assertNotNull($p, 'ohne Auswahl gilt der eingerichtete Anbieter');
        $ref = (string) self::db()->value('SELECT public_ref FROM bookings WHERE id = ?', [$p['booking_id']]);
        $sess = fn(string $pay, array $pi) => self::json(['id' => 'cs_test_sepa1', 'status' => 'complete', 'payment_status' => $pay, 'amount_total' => 24900, 'currency' => 'eur',
            'client_reference_id' => $ref, 'payment_intent' => $pi]);

        // SEPA: Sitzung abgeschlossen, Geld noch unterwegs -> weiter „in Prüfung“
        $this->useStripe([$sess('unpaid', ['status' => 'processing', 'latest_charge' => null])], $hist);
        self::assertSame('pending', $this->app->bookings()->completeReturn($ref, 'cs_test_sepa1'));
        self::assertSame('pending_payment', self::db()->value('SELECT status FROM bookings WHERE id = ?', [$p['booking_id']]));

        // später meldet Stripe den Zahlungseingang per Webhook
        $this->useStripe([$sess('paid', ['status' => 'succeeded', 'latest_charge' => ['id' => 'ch_sepa_1']])], $hist);
        self::assertSame(200, $this->stripeHook(['id' => 'evt_s1', 'type' => 'checkout.session.async_payment_succeeded', 'data' => ['object' => ['id' => 'cs_test_sepa1']]])->status);
        self::assertSame('confirmed', self::db()->value('SELECT status FROM bookings WHERE id = ?', [$p['booking_id']]));
    }

    public function test22_StripeWebhookSignatureAndMode(): void
    {
        $gw = new \Mea\Module\Payments\StripeGateway(new \GuzzleHttp\Client(), self::STRIPE_KEY, self::STRIPE_HOOK);
        $t = 1_700_000_000;
        $body = '{"id":"evt_x"}';
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::STRIPE_HOOK);
        self::assertTrue($gw->verifyWebhook(['stripe-signature' => $sig], $body, $t + 10));
        self::assertFalse($gw->verifyWebhook(['stripe-signature' => $sig], $body, $t + 4000), 'zu alt');
        self::assertFalse($gw->verifyWebhook(['stripe-signature' => $sig], $body . ' ', $t + 10), 'verändert');
        self::assertFalse($gw->verifyWebhook([], $body, $t));
        self::assertFalse((new \Mea\Module\Payments\StripeGateway(new \GuzzleHttp\Client(), self::STRIPE_KEY, ''))->verifyWebhook(['stripe-signature' => $sig], $body, $t));
        self::assertFalse((new \Mea\Module\Payments\StripeGateway(new \GuzzleHttp\Client(), '', ''))->isConfigured());
        self::assertSame('sandbox', $gw->mode());
        self::assertSame('live', (new \Mea\Module\Payments\StripeGateway(new \GuzzleHttp\Client(), 'sk_live_abcdefgh12345678', ''))->mode());
    }
}
