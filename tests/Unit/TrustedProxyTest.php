<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Core\Request;
use PHPUnit\Framework\TestCase;

final class TrustedProxyTest extends TestCase
{
    public function testWithoutTrustedProxiesHeadersAreIgnored(): void
    {
        $s = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertSame($s, Request::applyTrustedProxy($s, null));
        self::assertSame($s, Request::applyTrustedProxy($s, ''));
    }

    public function testVisitorIpAndHttpsComeFromTrustedProxy(): void
    {
        $s = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $r = Request::applyTrustedProxy($s, '127.0.0.1,::1');
        self::assertSame('203.0.113.9', $r['REMOTE_ADDR']);
        self::assertSame('on', $r['HTTPS']);
    }

    public function testForgedHeaderFromUntrustedClientIsIgnored(): void
    {
        $s = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        self::assertSame($s, Request::applyTrustedProxy($s, '127.0.0.1'));
    }

    public function testClientCannotPrependFakeAddress(): void
    {
        // Besucher schickt „1.2.3.4“ mit; der Proxy hängt die echte Adresse hinten an
        $s = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9'];
        self::assertSame('203.0.113.9', Request::applyTrustedProxy($s, '10.0.0.0/24')['REMOTE_ADDR']);
    }

    public function testGarbageHeaderKeepsProxyAddress(): void
    {
        $s = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'nonsense'];
        self::assertSame('127.0.0.1', Request::applyTrustedProxy($s, '127.0.0.1')['REMOTE_ADDR']);
    }
}
