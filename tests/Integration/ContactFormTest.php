<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\Csrf;

final class ContactFormTest extends IntegrationTestCase
{
    /** @return array<string,string> */
    private function valid(): array
    {
        $loc = (string) self::db()->value('SELECT id FROM locations ORDER BY id LIMIT 1');
        return [
            '_csrf' => Csrf::token(), '_t' => (string) (time() - 10), 'website' => '',
            'name' => 'Erika Muster', 'email' => 'erika@example.org', 'phone' => '',
            'topic' => 'pruefung', 'location' => $loc, 'message' => 'Wann ist der nächste B1-Termin?', 'privacy' => '1',
        ];
    }

    private function inquiryCount(): int
    {
        return (int) self::db()->value('SELECT COUNT(*) FROM inquiries');
    }

    public function testValidInquiryIsStored(): void
    {
        $before = $this->inquiryCount();
        $r = $this->request('POST', '/kontakt/', $this->valid());
        self::assertSame(303, $r->status);
        self::assertSame($before + 1, $this->inquiryCount());
        $row = self::db()->one('SELECT * FROM inquiries ORDER BY id DESC LIMIT 1');
        self::assertNotNull($row);
        self::assertStringNotContainsString('203.0.113.7', json_encode($row, JSON_THROW_ON_ERROR), 'IP nur als Hash');
    }

    public function testMissingCsrfIsRejected(): void
    {
        $before = $this->inquiryCount();
        $data = $this->valid();
        $data['_csrf'] = 'falsch';
        self::assertSame(400, $this->request('POST', '/kontakt/', $data)->status);
        self::assertSame($before, $this->inquiryCount());
    }

    public function testHoneypotAndTooFastAreSilentlyDropped(): void
    {
        $before = $this->inquiryCount();
        $bot = $this->valid();
        $bot['website'] = 'http://spam.example';
        self::assertSame(303, $this->request('POST', '/kontakt/', $bot)->status);
        $fast = $this->valid();
        $fast['_t'] = (string) time();
        self::assertSame(303, $this->request('POST', '/kontakt/', $fast)->status);
        self::assertSame($before, $this->inquiryCount());
    }

    public function testInvalidInputShowsErrors(): void
    {
        $data = $this->valid();
        $data['email'] = 'keine-adresse';
        $data['privacy'] = '';
        $r = $this->request('POST', '/kontakt/', $data);
        self::assertSame(422, $r->status);
        self::assertStringContainsString('aria-invalid="true"', $r->body);
    }

    public function testRateLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(303, $this->request('POST', '/kontakt/', $this->valid())->status);
        }
        self::assertSame(429, $this->request('POST', '/kontakt/', $this->valid())->status);
    }
}
