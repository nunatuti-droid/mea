<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Module\Ops\BackupCrypto;
use Mea\Module\Security\FileGuard;
use Mea\Module\Security\IpAddress;
use PHPUnit\Framework\TestCase;

final class OpsUnitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mea-unit-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testIpRanges(): void
    {
        self::assertTrue(IpAddress::inCidr('203.0.113.7', '203.0.113.0/24'));
        self::assertFalse(IpAddress::inCidr('203.0.114.7', '203.0.113.0/24'));
        self::assertTrue(IpAddress::inCidr('203.0.113.7', '203.0.113.7'));
        self::assertTrue(IpAddress::inCidr('10.1.2.3', '10.0.0.0/12'));
        self::assertTrue(IpAddress::inCidr('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(IpAddress::inCidr('2001:db9::1', '2001:db8::/32'));
        self::assertFalse(IpAddress::inCidr('203.0.113.7', '2001:db8::/32'));
        self::assertTrue(IpAddress::validCidr('203.0.113.0/24'));
        self::assertFalse(IpAddress::validCidr('203.0.113.0/4'), 'zu großer Bereich');
        self::assertFalse(IpAddress::validCidr('beispiel.de'));
        self::assertSame('203.0.113.x', IpAddress::mask('203.0.113.7'));
        self::assertSame('2001:0db8:0000:…', IpAddress::mask('2001:db8::1'));
    }

    public function testBackupEncryptionDetectsTamperingAndWrongKey(): void
    {
        $key = random_bytes(32);
        $plain = $this->dir . '/plain.bin';
        file_put_contents($plain, random_bytes(2_500_000)); // mehrere Blöcke
        $c = new BackupCrypto($key);
        $c->encryptFile($plain, $this->dir . '/enc');
        $c->decryptFile($this->dir . '/enc', $this->dir . '/dec');
        self::assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->dir . '/dec'));
        self::assertStringNotContainsString(substr((string) file_get_contents($plain), 1000, 64), (string) file_get_contents($this->dir . '/enc'));

        $tampered = (string) file_get_contents($this->dir . '/enc');
        $tampered[5000] = $tampered[5000] === 'A' ? 'B' : 'A';
        file_put_contents($this->dir . '/bad', $tampered);
        $this->assertDecryptFails($c, $this->dir . '/bad');

        file_put_contents($this->dir . '/short', substr((string) file_get_contents($this->dir . '/enc'), 0, 1_100_000));
        $this->assertDecryptFails($c, $this->dir . '/short');
        $this->assertDecryptFails(new BackupCrypto(random_bytes(32)), $this->dir . '/enc');
    }

    private function assertDecryptFails(BackupCrypto $c, string $file): void
    {
        try {
            $c->decryptFile($file, $this->dir . '/out');
            self::fail('Entschlüsselung hätte scheitern müssen');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testFileGuardDetectsChangesAgainstSignedReference(): void
    {
        $kp = FileGuard::generateKeyPair();
        mkdir($this->dir . '/src');
        mkdir($this->dir . '/public/media', 0777, true);
        file_put_contents($this->dir . '/src/A.php', '<?php // a');
        file_put_contents($this->dir . '/src/B.php', '<?php // b');
        file_put_contents($this->dir . '/public/index.php', '<?php // index');
        file_put_contents($this->dir . '/public/media/1-bild-480.webp', 'webp');
        (new FileGuard($this->dir, null))->writeSignedManifest($kp['secret'], '1.0.0');

        $guard = new FileGuard($this->dir, $kp['public']);
        self::assertSame('ok', $guard->check()['status']);
        self::assertSame('no_reference', (new FileGuard($this->dir, null))->check()['status']);

        file_put_contents($this->dir . '/src/A.php', '<?php // verändert');
        unlink($this->dir . '/src/B.php');
        file_put_contents($this->dir . '/public/shell.php', '<?php system($_GET[1]);');
        file_put_contents($this->dir . '/public/media/x.php', '<?php');
        $r = $guard->check();
        self::assertSame('changed', $r['status']);
        self::assertSame(['src/A.php'], $r['changed']);
        self::assertSame(['src/B.php'], $r['missing']);
        self::assertContains('public/shell.php', $r['unexpected']);
        self::assertContains('public/media/x.php', $r['unexpected']);

        // Angreifer schreibt eine neue Referenz ohne den geheimen Schlüssel
        $m = json_decode((string) file_get_contents($this->dir . '/integrity.json'), true);
        $m['files']['src/A.php'] = hash_file('sha256', $this->dir . '/src/A.php');
        file_put_contents($this->dir . '/integrity.json', json_encode($m));
        self::assertSame('invalid_reference', $guard->check()['status']);
        self::assertSame('invalid_reference', (new FileGuard($this->dir, FileGuard::generateKeyPair()['public']))->check()['status']);
    }
}
