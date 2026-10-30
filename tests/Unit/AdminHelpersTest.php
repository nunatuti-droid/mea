<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use Mea\Admin\Crud\Form;
use Mea\Admin\Resources\HolidayResource;
use Mea\Core\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class AdminHelpersTest extends TestCase
{
    public function testParsesGermanMoneyInput(): void
    {
        self::assertSame(15000, Form::parseMoney('150'));
        self::assertSame(15050, Form::parseMoney('150,50 €'));
        self::assertSame(123456, Form::parseMoney('1.234,56'));
        self::assertSame(990, Form::parseMoney('9.9'));
        self::assertNull(Form::parseMoney('-5'));
        self::assertNull(Form::parseMoney('abc'));
    }

    public function testConvertsBerlinTimeToUtcAcrossDst(): void
    {
        self::assertSame('2026-07-01 07:00:00', Form::localToUtc('2026-07-01T09:00'), 'Sommerzeit UTC+2');
        self::assertSame('2026-12-01 08:00:00', Form::localToUtc('2026-12-01T09:00'), 'Winterzeit UTC+1');
        self::assertSame('2026-12-01T09:00', Form::utcToLocal('2026-12-01 08:00:00'));
        self::assertNull(Form::localToUtc('01.12.2026'));
    }

    public function testNrwHolidays(): void
    {
        $h = HolidayResource::nrw(2026); // Ostersonntag 2026: 5. April
        self::assertSame('Karfreitag', $h['2026-04-03']);
        self::assertSame('Ostermontag', $h['2026-04-06']);
        self::assertSame('Christi Himmelfahrt', $h['2026-05-14']);
        self::assertSame('Pfingstmontag', $h['2026-05-25']);
        self::assertSame('Fronleichnam', $h['2026-06-04']);
        self::assertCount(11, $h);
        self::assertArrayHasKey('2027-03-26', HolidayResource::nrw(2027), 'Karfreitag 2027');
    }

    public function testSanitizerRemovesDangerousMarkup(): void
    {
        $s = new HtmlSanitizer(sys_get_temp_dir() . '/mea-purifier-test');
        $out = $s->clean('<p onclick="x()">Hallo <script>alert(1)</script><a href="javascript:alert(1)">a</a> <a href="https://example.org">b</a></p><iframe src="https://evil.test"></iframe><img src="https://evil.test/x.png" alt="x"><h2>Titel</h2>');
        self::assertStringNotContainsString('script', $out);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringNotContainsString('iframe', $out);
        self::assertStringNotContainsString('evil.test/x.png', $out);
        self::assertStringContainsString('<h2>Titel</h2>', $out);
        self::assertStringContainsString('href="https://example.org"', $out);
        self::assertStringContainsString('rel="noopener"', $out);
    }
}
