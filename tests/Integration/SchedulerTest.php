<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Install\DemoSeeder;
use Mea\Module\Ops\Scheduler;

/** Nächtliche Wartung: Fristen, Erinnerungen, Takt und Wiederholung. */
final class SchedulerTest extends IntegrationTestCase
{
    private function participant(int $bookingId, string $email): int
    {
        return self::db()->insert('booking_participants', [
            'booking_id' => $bookingId, 'first_name' => 'Erika', 'last_name' => 'Muster', 'email' => $email,
            'birth_date' => '1990-01-01', 'street' => 'Teststraße 1', 'postal_code' => '50667', 'city' => 'Köln',
        ]);
    }

    private function booking(int $examId, int $sessionId, string $status, string $payment, string $createdAt): int
    {
        return self::db()->insert('bookings', [
            'public_ref' => strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)), 'exam_session_id' => $sessionId, 'exam_type_id' => $examId,
            'snapshot_json' => json_encode((new DemoSeeder(self::db()))->demoSnapshot($sessionId)), 'total_cents' => 100, 'currency' => 'EUR',
            'status' => $status, 'payment_status' => $payment, 'created_at' => $createdAt,
        ]);
    }

    public function testRetentionAnonymizesOnlyDueRecords(): void
    {
        $db = self::db();
        $now = new \DateTimeImmutable('2026-10-30 03:00:00', new \DateTimeZone('UTC'));
        $old = $db->insert('inquiries', ['name' => 'Alt', 'email' => 'alt@example.org', 'message' => 'x', 'privacy_accepted_at' => '2025-01-01', 'created_at' => '2025-01-01']);
        $new = $db->insert('inquiries', ['name' => 'Neu', 'email' => 'neu@example.org', 'message' => 'x', 'privacy_accepted_at' => '2026-10-01', 'created_at' => '2026-10-01']);

        $exam = $this->createExam('ret');
        $session = $this->createSession($exam);
        $unpaidOld = $this->participant($this->booking($exam, $session, 'expired', 'none', '2026-08-01'), 'u1@example.org');
        $unpaidNew = $this->participant($this->booking($exam, $session, 'expired', 'none', '2026-10-25'), 'u2@example.org');
        // Bezahlte Buchung eines aktuellen Termins bleibt (Aufbewahrungspflicht)
        $paid = $this->participant($this->booking($exam, $session, 'confirmed', 'paid', '2025-01-01'), 'p@example.org');
        // Bezahlte Buchung, deren Prüfungsjahr länger als 10 Jahre zurückliegt
        $ancientSession = $this->createSession($exam, 10, '2014-05-01 09:00', '2014-04-20');
        $paidAncient = $this->participant($this->booking($exam, $ancientSession, 'confirmed', 'paid', '2014-04-01'), 'a@example.org');

        $r = (new Scheduler($this->app))->run($now, ['retention']);
        self::assertStringContainsString('Anfragen', $r['retention']);

        $anon = fn(string $t, int $id) => $db->value("SELECT anonymized_at IS NOT NULL FROM $t WHERE id = ?", [$id]);
        self::assertSame(1, (int) $anon('inquiries', $old));
        self::assertSame(0, (int) $anon('inquiries', $new));
        self::assertSame(1, (int) $anon('booking_participants', $unpaidOld));
        self::assertSame(0, (int) $anon('booking_participants', $unpaidNew));
        self::assertSame(0, (int) $anon('booking_participants', $paid));
        self::assertSame(1, (int) $anon('booking_participants', $paidAncient));
        self::assertSame('anonym@invalid', $db->value('SELECT email FROM booking_participants WHERE id = ?', [$paidAncient]));
        self::assertNull($db->value('SELECT birth_date FROM booking_participants WHERE id = ?', [$unpaidOld]));
    }

    public function testRemindersAreSentOnceForConfirmedBookingsInWindow(): void
    {
        $db = self::db();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $exam = $this->createExam('rem');
        $soon = $this->createSession($exam, 10, '+2 days', '-1 day');
        $later = $this->createSession($exam, 10, '+20 days', '+5 days');
        $b1 = $this->booking($exam, $soon, 'confirmed', 'paid', $now->format('Y-m-d H:i:s'));
        $this->participant($b1, 'soon@example.org');
        $this->participant($this->booking($exam, $soon, 'cancelled', 'none', $now->format('Y-m-d H:i:s')), 'cancel@example.org');
        $this->participant($this->booking($exam, $later, 'confirmed', 'paid', $now->format('Y-m-d H:i:s')), 'later@example.org');

        $s = new Scheduler($this->app);
        self::assertSame('1 Erinnerungen', $s->run($now, ['reminders'])['reminders']);
        self::assertSame('0 Erinnerungen', $s->run($now, ['reminders'])['reminders']);
        $mails = $db->all("SELECT to_email FROM email_outbox WHERE template_key = 'session_reminder'");
        self::assertSame(['soon@example.org'], array_column($mails, 'to_email'));
    }

    public function testNightlyJobsRunOnceAfterTwoAndRetryAfterFailure(): void
    {
        $db = self::db();
        $s = new Scheduler($this->app);
        $utc = new \DateTimeZone('UTC');
        // 00:30 UTC = 01:30 Ortszeit (Winterzeit): noch nicht fällig
        $early = $s->run(new \DateTimeImmutable('2026-11-10 00:30:00', $utc), null);
        self::assertArrayHasKey('reservations', $early);
        self::assertArrayNotHasKey('holidays', $early);
        // 01:05 UTC = 02:05 Ortszeit: fällig
        $s->run(new \DateTimeImmutable('2026-11-10 01:05:00', $utc), null);
        self::assertSame('ok', $db->value("SELECT last_status FROM cron_runs WHERE job = 'holidays'"));
        self::assertGreaterThan(0, (int) $db->value("SELECT COUNT(*) FROM holidays WHERE holiday_date = '2027-12-25'"));
        // Am selben Tag kein zweiter Lauf
        $again = $s->run(new \DateTimeImmutable('2026-11-10 05:00:00', $utc), null);
        self::assertArrayNotHasKey('holidays', $again);
        self::assertArrayHasKey('cleanup', $again);

        // Fehlgeschlagener Job: 30 Minuten Pause, danach neuer Versuch
        $db->run("UPDATE cron_runs SET last_status = 'failed', last_success_at = '2026-11-09 01:00:00', last_started_at = '2026-11-10 06:00:00' WHERE job = 'holidays'");
        self::assertArrayNotHasKey('holidays', $s->run(new \DateTimeImmutable('2026-11-10 06:10:00', $utc), null));
        self::assertArrayHasKey('holidays', $s->run(new \DateTimeImmutable('2026-11-10 06:40:00', $utc), null));
    }

    public function testCleanupRemovesExpiredBansAndOldEvents(): void
    {
        $db = self::db();
        $db->insert('ip_rules', ['rule' => 'block', 'cidr' => '198.51.100.9/32', 'ip_masked' => '198.51.100.x', 'source' => 'auto', 'expires_at' => '2020-01-01 00:00:00']);
        $db->insert('ip_rules', ['rule' => 'block', 'cidr' => '198.51.100.10/32', 'ip_masked' => '198.51.100.x', 'source' => 'manual']);
        $db->insert('security_events', ['event_type' => 'probe', 'severity' => 'warning', 'points' => 1, 'created_at' => '2020-01-01 00:00:00']);
        (new Scheduler($this->app))->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), ['cleanup']);
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM ip_rules WHERE cidr = '198.51.100.9/32'"));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM ip_rules WHERE cidr = '198.51.100.10/32'"));
        self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM security_events WHERE created_at < '2021-01-01'"));
    }
}
