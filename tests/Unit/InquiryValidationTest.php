<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Module\Inquiries\InquiryService;
use PHPUnit\Framework\TestCase;

final class InquiryValidationTest extends TestCase
{
    private function svc(): InquiryService
    {
        return (new \ReflectionClass(InquiryService::class))->newInstanceWithoutConstructor();
    }

    public function testValidInquiryHasNoErrors(): void
    {
        $e = $this->svc()->validate([
            'name' => 'Ana Muster', 'email' => 'ana@example.org', 'phone' => '+49 2365 123',
            'topic' => 'pruefung', 'location' => '1', 'message' => 'Ich hätte gerne einen Termin.', 'privacy' => '1',
        ], [1, 2]);
        self::assertSame([], $e);
    }

    public function testRejectsBadInput(): void
    {
        $e = $this->svc()->validate([
            'name' => 'A', 'email' => 'kein-mail', 'phone' => '<script>', 'topic' => 'erfunden',
            'location' => '99', 'message' => 'kurz', 'privacy' => '',
        ], [1, 2]);
        self::assertSame(['name', 'email', 'phone', 'topic', 'location', 'message', 'privacy'], array_keys($e));
    }
}
