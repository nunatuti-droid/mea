<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Umzug: Protokoll nicht gefundener Adressen und Weiterleitungen in der Verwaltung. */
final class MigrationTest extends IntegrationTestCase
{
    public function testWildcardRulesAndNotFoundLog(): void
    {
        // Die Weiterleitungen der alten WordPress-Seite gibt es nicht mehr
        self::assertSame(0, (int) self::db()->value("SELECT COUNT(*) FROM redirects WHERE source_path LIKE '%*'"));
        self::assertSame(404, $this->get('/wirklich-unbekannt/')->status);
        $this->get('/wirklich-unbekannt/');
        self::assertSame(2, (int) self::db()->value("SELECT hits FROM not_found_log WHERE path = '/wirklich-unbekannt/'"));
        // Keine Admin-, Buchungs- oder Dateisuche-Adressen im Protokoll
        $this->get('/buchung/MEA-XXXXXXXX/');
        $this->get('/alt.php');
        self::assertSame(0, (int) self::db()->value("SELECT COUNT(*) FROM not_found_log WHERE path LIKE '/buchung/%' OR path = '/alt.php'"));
    }

    public function testRedirectsAreManagedInBackend(): void
    {
        $this->loginAs('editor');
        $this->get('/weg-damit/');
        $form = $this->get('/verwaltung/weiterleitungen/neu/?quelle=%2Fweg-damit%2F');
        self::assertSame(200, $form->status);
        self::assertStringContainsString('value="/weg-damit/"', $form->body);
        self::assertStringContainsString('/weg-damit/', $this->get('/verwaltung/weiterleitungen/')->body);

        // Ungültig: Endlosschleife, Admin-Pfad, 410 mit Ziel
        foreach ([
            ['source_path' => '/a/*', 'status_code' => '301', 'target_url' => '/a/b/'],
            ['source_path' => '/verwaltung/x/', 'status_code' => '301', 'target_url' => '/'],
            ['source_path' => '/b/', 'status_code' => '410', 'target_url' => '/'],
            ['source_path' => 'ohne-slash', 'status_code' => '301', 'target_url' => '/'],
            ['source_path' => '/c/', 'status_code' => '301', 'target_url' => 'javascript:alert(1)'],
        ] as $bad) {
            self::assertSame(422, $this->adminPost('/verwaltung/weiterleitungen/neu/', $bad)->status, json_encode($bad));
        }

        $r = $this->adminPost('/verwaltung/weiterleitungen/neu/', ['source_path' => '/weg-damit/', 'status_code' => '301', 'target_url' => '/kontakt/']);
        self::assertSame(303, $r->status, (string) $r->body);
        $_SESSION = [];
        $go = $this->get('/weg-damit/');
        self::assertSame(301, $go->status);
        self::assertSame('/kontakt/', $go->headers['Location']);
        self::assertNotNull(self::db()->value("SELECT resolved_at FROM not_found_log WHERE path = '/weg-damit/'"));
    }

    public function testBookingsFromOldShopCanBeTakenOver(): void
    {
        $this->loginAs('booking');
        $exam = $this->createExam('uebernahme');
        $session = $this->createSession($exam, 1);
        self::assertSame(200, $this->get('/verwaltung/buchungen/uebernehmen/?termin=' . $session)->status);
        $data = ['session_id' => (string) $session, 'first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'Erika@Example.org',
            'birth_date' => '1990-05-01', 'amount' => '189,00', 'paid' => '1', 'external_ref' => 'WC-4711', 'note' => 'aus WooCommerce'];

        self::assertSame(422, $this->adminPost('/verwaltung/buchungen/uebernehmen/', ['external_ref' => ''] + $data)->status, 'bezahlt ohne Referenz');
        $r = $this->adminPost('/verwaltung/buchungen/uebernehmen/', $data);
        self::assertSame(303, $r->status, (string) $r->body);
        $id = $this->idFromLocation($r);
        $b = self::db()->one('SELECT * FROM bookings WHERE id = ?', [$id]);
        self::assertSame('confirmed', $b['status']);
        self::assertSame('paid', $b['payment_status']);
        self::assertSame(18900, (int) $b['total_cents']);
        self::assertSame('erika@example.org', self::db()->value('SELECT email FROM booking_participants WHERE booking_id = ?', [$id]));
        self::assertSame('captured', self::db()->value("SELECT status FROM payments WHERE booking_id = ? AND provider = 'extern'", [$id]));
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM email_outbox WHERE related_id = ?', [$id]), 'ohne Häkchen keine E-Mail');
        $show = $this->get('/verwaltung/buchungen/' . $id . '/');
        self::assertSame(200, $show->status);
        self::assertStringContainsString('WC-4711', $show->body);

        // Gleiche Referenz nicht doppelt; voller Termin wird abgelehnt
        $dup = $this->adminPost('/verwaltung/buchungen/uebernehmen/', $data);
        self::assertSame(422, $dup->status);
        self::assertStringContainsString('bereits übernommen', $dup->body);
        $full = $this->adminPost('/verwaltung/buchungen/uebernehmen/', ['external_ref' => 'WC-4712'] + $data);
        self::assertStringContainsString('voll', $full->body);
        // Die Website zeigt den Termin als ausgebucht
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM bookings WHERE exam_session_id = ? AND status = 'confirmed'", [$session]));
        // Erstattung alter Zahlungen wird hier nicht versucht
        $this->expectException(\Mea\Module\Bookings\BookingException::class);
        $this->app->bookings()->refund($id, null, 'Test', null);
    }

    public function testGoLiveCheckReportsOpenPoints(): void
    {
        $rows = [];
        foreach ((new \Mea\Module\Ops\GoLiveCheck($this->app))->run() as $r) {
            $rows[$r['group'] . '/' . $r['label']] = $r['status'];
        }
        // AGB enthält noch den Platzhalter; die Datenschutzerklärung ist ausformuliert. PayPal ist im Test nicht eingerichtet
        self::assertSame('error', $rows['Livegang/AGB']);
        self::assertSame('ok', $rows['Livegang/Datenschutzerklärung']);
        self::assertSame('error', $rows['Livegang/PayPal-Modus']);
    }

    public function testLoopbackAddressIsNeverBanned(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->request('GET', '/wp-login.php', [], ['REMOTE_ADDR' => '127.0.0.1']);
        }
        self::assertSame(200, $this->request('GET', '/', [], ['REMOTE_ADDR' => '127.0.0.1'])->status);
        self::assertGreaterThan(0, (int) self::db()->value("SELECT COUNT(*) FROM security_events WHERE event_type = 'proxy_ip'"));
    }
}
