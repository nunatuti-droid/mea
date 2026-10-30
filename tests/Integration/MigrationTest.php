<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Umzug: Jede Adresse der alten WordPress-Sitemap führt zu einer Seite, einer Weiterleitung oder 410. */
final class MigrationTest extends IntegrationTestCase
{
    /** @return list<string> */
    private static function oldUrls(): array
    {
        $lines = file(self::root() . '/docs/migration/alte-urls.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '' && $l[0] === '/'));
    }

    public function testEveryOldUrlIsHandled(): void
    {
        // Vor dem Go-live werden die Prüfungen mit echten Preisen veröffentlicht (Go-live-Checkliste);
        // bin/console golive:check prüft dieselbe Liste gegen die echte Installation.
        self::db()->run("UPDATE exam_types SET status = 'published'");
        $urls = self::oldUrls();
        self::assertGreaterThan(80, count($urls));
        $problems = [];
        foreach ($urls as $u) {
            $r = $this->get($u);
            if ($r->status === 301) {
                $target = (string) parse_url((string) $r->headers['Location'], PHP_URL_PATH);
                $t = $this->get($target);
                if ($t->status !== 200) {
                    $problems[] = "$u → $target liefert {$t->status}";
                }
            } elseif (!in_array($r->status, [200, 410], true)) {
                $problems[] = "$u liefert {$r->status}";
            }
        }
        self::assertSame([], $problems);
    }

    public function testWildcardRulesAndNotFoundLog(): void
    {
        self::assertSame(410, $this->get('/edublink_footer/irgendwas/')->status);
        self::assertSame(301, $this->get('/product/neues-produkt/')->status);
        // Genaue Regel geht vor Platzhalter
        self::assertSame(410, $this->get('/courses/grow-personal-financial-security-thinking-principles-2/')->status);

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
        // Startdaten enthalten Platzhalter in den Rechtstexten; PayPal ist im Test nicht eingerichtet
        self::assertSame('error', $rows['Livegang/AGB']);
        self::assertSame('error', $rows['Livegang/Datenschutzerklärung']);
        self::assertSame('error', $rows['Livegang/PayPal-Modus']);
        self::assertArrayHasKey('Livegang/Alte Adressen', $rows);
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
