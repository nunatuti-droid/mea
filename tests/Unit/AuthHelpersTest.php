<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Core\SystemClock;
use Mea\Module\Auth\PasswordPolicy;
use Mea\Module\Auth\Totp;
use OTPHP\TOTP as OtpTotp;
use PHPUnit\Framework\TestCase;

final class AuthHelpersTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private function codeAt(int $ts): string
    {
        return OtpTotp::createFromSecret(self::SECRET)->at($ts);
    }

    public function testTotpAcceptsCurrentAndNeighbourStepOnly(): void
    {
        $ts = 1_800_000_000;
        $totp = new Totp(new SystemClock((new \DateTimeImmutable())->setTimestamp($ts)));
        $step = intdiv($ts, 30);
        self::assertSame($step, $totp->verify(self::SECRET, $this->codeAt($ts), null));
        self::assertSame($step - 1, $totp->verify(self::SECRET, $this->codeAt($ts - 30), null));
        self::assertNull($totp->verify(self::SECRET, $this->codeAt($ts - 120), null), 'zu alter Code');
        self::assertNull($totp->verify(self::SECRET, '12345', null), 'falsches Format');
    }

    public function testTotpCodeCannotBeReused(): void
    {
        $ts = 1_800_000_000;
        $totp = new Totp(new SystemClock((new \DateTimeImmutable())->setTimestamp($ts)));
        $step = $totp->verify(self::SECRET, $this->codeAt($ts), null);
        self::assertNotNull($step);
        self::assertNull($totp->verify(self::SECRET, $this->codeAt($ts), $step), 'Wiederverwendung abgelehnt');
    }

    public function testPasswordPolicy(): void
    {
        self::assertNotNull(PasswordPolicy::check('kurz'));
        self::assertNotNull(PasswordPolicy::check('aaaaaaaaaaaaaaaa'), 'zu wenige verschiedene Zeichen');
        self::assertNotNull(PasswordPolicy::check('mariamueller-2026!', 'mariamueller@example.org'), 'enthält E-Mail');
        self::assertNull(PasswordPolicy::check('Lange sichere Passphrase 2026', 'admin@example.org'));
        $hash = PasswordPolicy::hash('Lange sichere Passphrase 2026');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(password_verify('Lange sichere Passphrase 2026', $hash));
    }
}
