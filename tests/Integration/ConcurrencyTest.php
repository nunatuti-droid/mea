<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Gleichzeitige Buchungen in getrennten PHP-Prozessen: Es werden nie mehr Plätze vergeben als vorhanden. */
final class ConcurrencyTest extends IntegrationTestCase
{
    protected bool $transactional = false;

    public function testParallelBookingsNeverOverbook(): void
    {
        $exam = $this->createExam('parallel');
        $session = $this->createSession($exam, 3);
        $env = getenv();
        $env['MEA_TEST_DB'] = (string) self::db()->value('SELECT DATABASE()');
        $start = sprintf('%.3f', microtime(true) + 1.5);
        $procs = [];
        for ($i = 0; $i < 12; $i++) {
            $procs[] = proc_open([PHP_BINARY, self::root() . '/tests/Support/reserve_worker.php', (string) $exam, (string) $session, $start],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), $env) ?: null;
            $out[$i] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $results[] = trim(stream_get_contents($out[$i][1]) . stream_get_contents($out[$i][2]));
            proc_close($p);
        }
        $ok = count(array_filter($results, fn($r) => str_starts_with($r, 'ok ')));
        $full = count(array_filter($results, fn($r) => str_contains($r, 'ausgebucht')));
        self::assertSame(3, $ok, implode("\n", $results));
        self::assertSame(9, $full, implode("\n", $results));
        self::assertSame(3, (int) self::db()->value('SELECT COUNT(*) FROM bookings WHERE exam_session_id = ?', [$session]));
    }

    /** Belegt: Die Buchung wartet auf die Sperre des Termins und zählt danach neu. */
    public function testBookingWaitsForSessionLock(): void
    {
        $exam = $this->createExam('sperre');
        $session = $this->createSession($exam, 1);
        $env = getenv();
        $env['MEA_TEST_DB'] = (string) self::db()->value('SELECT DATABASE()');
        $pdo = self::db()->pdo();
        $pdo->beginTransaction();
        self::db()->one('SELECT id FROM exam_sessions WHERE id = ? FOR UPDATE', [$session]);
        $t0 = microtime(true);
        $p = proc_open([PHP_BINARY, self::root() . '/tests/Support/reserve_worker.php', (string) $exam, (string) $session, '0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), $env);
        usleep(2_000_000);
        // Während der Hilfsprozess wartet, wird der letzte Platz vergeben
        $this->book($exam, $session, 'confirmed');
        $pdo->commit();
        $result = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        proc_close($p);
        self::assertGreaterThan(1.9, microtime(true) - $t0);
        self::assertStringContainsString('ausgebucht', $result);
    }
}
