<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** telc-Prüfungen finden nur im Prüfungszentrum Herne statt. */
final class ExamCenterTest extends IntegrationTestCase
{
    public function testOnlyHerneIsExamCenter(): void
    {
        self::assertSame('herne', $this->app->locations->examCenter()['slug'] ?? null);
    }

    public function testPublicPagesShowVenueNotice(): void
    {
        $body = $this->get('/telc-pruefungszentrum/')->body;
        self::assertStringContainsString('ausschließlich in unserem zertifizierten telc-Prüfungszentrum', $body);
        self::assertStringContainsString('Online-Prüfungen bieten wir nicht an', $body);
        self::assertStringContainsString('An diesem Standort finden keine telc-Prüfungen statt', $this->get('/standorte/marl/')->body);
        self::assertStringContainsString('Zertifiziertes telc-Prüfungszentrum', $this->get('/standorte/herne/')->body);
    }

    public function testAdminCannotCreateSessionOutsideExamCenter(): void
    {
        $this->loginAs('admin');
        $exam = $this->createExam('zentrum-test');
        $marl = (int) self::db()->value("SELECT id FROM locations WHERE slug = 'marl'");
        $herne = (int) self::db()->value("SELECT id FROM locations WHERE slug = 'herne'");
        $form = $this->get('/verwaltung/termine/neu/')->body;
        self::assertStringNotContainsString('value="' . $marl . '"', $this->locationSelect($form));
        self::assertStringContainsString('value="' . $herne . '"', $this->locationSelect($form));

        $data = ['exam_type_id' => (string) $exam, 'starts_at' => (new \DateTimeImmutable('+40 days 09:00', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d\TH:i'),
            'ends_at' => '', 'registration_until' => '', 'capacity' => '10', 'price_cents' => '', 'booking_rule_id' => '', 'status' => 'open',
            'is_visible' => '1', 'notes_public' => '', 'notes_internal' => ''];
        $r = $this->adminPost('/verwaltung/termine/neu/', $data + ['location_id' => (string) $marl]);
        self::assertSame(422, $r->status);
        self::assertMatchesRegularExpression('#name="location_id"[^>]*aria-invalid="true"#', $r->body);
        $r = $this->adminPost('/verwaltung/termine/neu/', $data + ['location_id' => (string) $herne]);
        self::assertSame(303, $r->status, substr($r->body, 0, 500));
    }

    private function locationSelect(string $html): string
    {
        return preg_match('#<select[^>]*name="location_id".*?</select>#s', $html, $m) ? $m[0] : '';
    }
}
