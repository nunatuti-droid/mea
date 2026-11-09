<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Media\ImageVariantGenerator;

/** Sicherheitstests aus dem Abnahmekatalog (Abschnitt 21), soweit nicht in anderen Tests abgedeckt. */
final class SecurityTest extends IntegrationTestCase
{
    public function testSqlInjectionPayloadsAreTreatedAsData(): void
    {
        $this->loginAs('admin');
        $payload = "x'; DROP TABLE bookings; --";
        $r = $this->get('/verwaltung/buchungen/?q=' . rawurlencode($payload));
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Keine Treffer', $r->body);
        self::assertNotNull(self::db()->value("SHOW TABLES LIKE 'bookings'"));
        // Klassische Muster werden zusätzlich vom Anwendungsschutz abgewiesen
        self::assertSame(403, $this->get('/telc-pruefungszentrum/?q=' . rawurlencode("1' OR '1'='1"))->status);
    }

    public function testStoredScriptIsEscapedInBackend(): void
    {
        $id = self::db()->insert('inquiries', [
            'name' => '<script>alert(1)</script>', 'email' => 'x@example.org',
            'message' => '<img src=x onerror=alert(2)>', 'privacy_accepted_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->loginAs('admin');
        foreach (['/verwaltung/anfragen/', '/verwaltung/anfragen/' . $id . '/'] as $url) {
            $body = $this->get($url)->body;
            self::assertStringNotContainsString('<script>alert(1)', $body, $url);
            self::assertStringNotContainsString('<img src=x', $body, $url);
        }
    }

    public function testUploadsRejectScriptsAndDisguisedFiles(): void
    {
        $tmp = sys_get_temp_dir() . '/mea-upl-' . getmypid();
        @mkdir($tmp, 0700, true);
        $gen = new ImageVariantGenerator(self::db(), $tmp . '/uploads', $tmp . '/public');
        $cases = [
            'shell.jpg' => "<?php system(\$_GET['c']); ?>",
            'bild.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'gif.jpg' => "GIF89a<?php echo 1; ?>",
        ];
        foreach ($cases as $name => $content) {
            file_put_contents("$tmp/$name", $content);
            try {
                $gen->import("$tmp/$name", 'Test');
                self::fail("$name wurde angenommen");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        file_put_contents("$tmp/fake.pdf", "<?php echo 1; ?>");
        try {
            $gen->importDocument("$tmp/fake.pdf", 'fake.pdf', 'Fake');
            self::fail('Gefälschtes PDF wurde angenommen');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        // Echtes Bild: gespeicherter Name ist zufällig, nicht der hochgeladene
        $img = imagecreatetruecolor(600, 400);
        imagejpeg($img, "$tmp/../evil.php.jpg");
        $id = $gen->import("$tmp/../evil.php.jpg", 'Testbild', '', '', '../../evil.php.jpg');
        $stored = (string) self::db()->value('SELECT storage_path FROM media WHERE id = ?', [$id]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\.jpg$/', basename($stored));
        foreach (glob($tmp . '/public/*/*') ?: [] as $f) {
            self::assertStringEndsWith('.webp', $f);
        }
        @unlink("$tmp/../evil.php.jpg");
        (new \Mea\Module\Ops\BackupService(self::db(), $tmp, new \Mea\Module\Ops\BackupCrypto(random_bytes(32)), new \Psr\Log\NullLogger(), '0'))->removeDir($tmp);
    }

    public function testInvitationTokenWorksOnlyOnce(): void
    {
        $inv = $this->app->auth->invite('neu-' . bin2hex(random_bytes(3)) . '@example.org', 'Neue Person', 'editor', null);
        self::assertNotNull($this->app->auth->acceptInvite($inv['token'], 'Neue Person', 'Lange sichere Passphrase 2026'));
        self::assertNull($this->app->auth->acceptInvite($inv['token'], 'Angreifer', 'Andere lange Passphrase 2026'));
        self::assertSame(410, $this->get('/verwaltung/einladung/' . $inv['token'] . '/')->status);
    }

    public function testCustomerCannotSeeBookingWithoutCorrectKey(): void
    {
        $exam = $this->createExam('fremd');
        $session = $this->createSession($exam);
        $key = \Mea\Core\Crypto::token();
        $id = self::db()->insert('bookings', [
            'public_ref' => 'MEA-ABCDEFGH', 'access_key_hash' => $this->app->crypto->hash($key, 'booking'), 'exam_session_id' => $session,
            'exam_type_id' => $exam, 'snapshot_json' => json_encode((new \Mea\Install\DemoSeeder(self::db()))->demoSnapshot($session)),
            'total_cents' => 100, 'currency' => 'EUR', 'status' => 'confirmed',
        ]);
        self::db()->insert('booking_participants', ['booking_id' => $id, 'first_name' => 'Erika', 'last_name' => 'Geheim', 'email' => 'e@example.org']);
        self::assertSame(404, $this->get('/buchung/MEA-ABCDEFGH/')->status);
        self::assertSame(404, $this->get('/buchung/MEA-ABCDEFGH/?k=' . str_repeat('A', 43))->status);
        $ok = $this->get('/buchung/MEA-ABCDEFGH/?k=' . $key);
        self::assertSame(200, $ok->status);
        self::assertStringContainsString('no-store', $ok->headers['Cache-Control'] ?? '');
    }
}
