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

    /** Öffentliches Buchungsformular absenden. @return Response */
    private function submitBooking(string $slug, int $sessionId, string $email = 'lea@example.org'): Response
    {
        $form = $this->get("/pruefungen/$slug/buchen/$sessionId/");
        return $this->request('POST', "/pruefungen/$slug/buchen/$sessionId/", [
            '_csrf' => Csrf::token(), '_t' => (string) (time() - 30), 'website' => '',
            'salutation' => 'Frau', 'first_name' => 'Lea', 'last_name' => 'Beispiel', 'email' => $email, 'phone' => '0201 123456',
            'birth_date' => '1990-04-12', 'birth_place' => 'Essen', 'street' => 'Musterweg 1', 'postal_code' => '45127', 'city' => 'Essen',
            'terms' => '1', 'privacy' => '1',
        ], ['REMOTE_ADDR' => '198.51.100.' . random_int(1, 250)]);
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
        $overview = $this->get('/telc-pruefungszentrum/');
        self::assertStringContainsString('telc Deutsch Pflege B1-B2', $overview->body);
        self::assertStringContainsString('/pruefungen/telc-deutsch-pflege-b1-b2/', $overview->body);

        $d = $this->get('/pruefungen/telc-deutsch-pflege-b1-b2/');
        self::assertSame(200, $d->status);
        self::assertStringContainsString('<title>telc Pflege B1-B2 Prüfung in Marl</title>', $d->body);
        self::assertStringContainsString('<meta name="description" content="Pflegeprüfung B1-B2 beim MEA Bildungszentrum: Termine und Anmeldung.">', $d->body);
        self::assertStringContainsString('<link rel="canonical" href="https://mea.test/pruefungen/telc-deutsch-pflege-b1-b2/">', $d->body);
        self::assertStringContainsString('/pruefungen/telc-deutsch-pflege-b1-b2/', $this->get('/sitemap.xml')->body);
        self::assertStringContainsString('telc Deutsch Pflege B1-B2', $this->get('/preise/')->body);
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
        self::assertStringNotContainsString('telc-deutsch-pflege-b1-b2', $this->get('/telc-pruefungszentrum/')->body);
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
        $overview = $this->get('/telc-pruefungszentrum/')->body;
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
}
