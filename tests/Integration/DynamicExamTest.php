<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/**
 * Abnahmekriterium: Neue Prüfungen entstehen nur durch Daten – ohne Code, Templates oder Tabellen.
 */
final class DynamicExamTest extends IntegrationTestCase
{
    public function testNewExamAppearsOnDetailOverviewPricesAndSitemap(): void
    {
        $id = $this->createExam('testpruefung-z9');
        $this->createSession($id, 10, '+30 days', '+16 days', 9900);

        $detail = $this->get('/pruefungen/testpruefung-z9/');
        self::assertSame(200, $detail->status);
        self::assertStringContainsString('Testprüfung TESTPRUEFUNG-Z9', $detail->body);
        self::assertStringContainsString('99,00', $detail->body, 'Terminpreis geht vor Grundpreis');

        self::assertStringContainsString('/pruefungen/testpruefung-z9/', $this->get('/telc-pruefungszentrum/')->body);
        self::assertStringContainsString('Testprüfung TESTPRUEFUNG-Z9', $this->get('/preise/')->body);
        self::assertStringContainsString('https://mea.test/pruefungen/testpruefung-z9/', $this->get('/sitemap.xml')->body);
        self::assertStringContainsString('Z9', $this->get('/telc-pruefungszentrum/')->body, 'neues Niveau im Filter');
    }

    public function testDraftIsHiddenEverywhere(): void
    {
        $this->createExam('entwurf-z9', 'draft');
        self::assertSame(404, $this->get('/pruefungen/entwurf-z9/')->status);
        self::assertStringNotContainsString('entwurf-z9', $this->get('/telc-pruefungszentrum/')->body);
        self::assertStringNotContainsString('entwurf-z9', $this->get('/sitemap.xml')->body);
    }

    public function testDisabledExamIsHiddenAndNotBookable(): void
    {
        $id = $this->createExam('deaktiviert-z9', 'disabled');
        $sid = $this->createSession($id);
        $r = $this->get('/pruefungen/deaktiviert-z9/');
        self::assertSame(302, $r->status);
        self::assertSame('/telc-pruefungszentrum/', $r->headers['Location']);
        self::assertStringNotContainsString('deaktiviert-z9', $this->get('/telc-pruefungszentrum/')->body);
        self::assertStringNotContainsString('deaktiviert-z9', $this->get('/sitemap.xml')->body);
        self::assertNotSame(200, $this->get("/pruefungen/deaktiviert-z9/buchen/$sid/")->status);
    }

    public function testQuickToggleInAdminList(): void
    {
        $this->loginAs('editor');
        $id = $this->createExam('schalter-z9');
        self::assertStringContainsString('/verwaltung/pruefungen/' . $id . '/status/', $this->get('/verwaltung/pruefungen/')->body);
        self::assertSame(303, $this->adminPost('/verwaltung/pruefungen/' . $id . '/status/', ['value' => 'disabled'])->status);
        self::assertSame('disabled', self::db()->value('SELECT status FROM exam_types WHERE id = ?', [$id]));
        self::assertSame(302, $this->get('/pruefungen/schalter-z9/')->status);
        $this->adminPost('/verwaltung/pruefungen/' . $id . '/status/', ['value' => 'published']);
        self::assertSame('published', self::db()->value('SELECT status FROM exam_types WHERE id = ?', [$id]));
        self::assertSame(200, $this->get('/pruefungen/schalter-z9/')->status);
        self::assertNotNull(self::db()->value("SELECT 1 FROM audit_log WHERE action = 'exam_type.status' AND entity_id = ?", [$id]));
    }

    public function testCategoriesGroupOverview(): void
    {
        $cat = (int) self::db()->value("SELECT id FROM exam_categories WHERE slug = 'pflege-medizin'");
        $id = $this->createExam('pflege-z9');
        self::db()->run('UPDATE exam_types SET category_id = ? WHERE id = ?', [$cat, $id]);
        $general = $this->createExam('allgemein-z9');
        self::db()->run("UPDATE exam_types SET category_id = (SELECT id FROM exam_categories WHERE slug = 'allgemeinsprachlich') WHERE id = ?", [$general]);
        $body = $this->get('/telc-pruefungszentrum/')->body;
        self::assertStringContainsString('Deutschprüfungen für Pflege und Medizin', $body);
        self::assertStringContainsString('Allgemeinsprachliche Deutschprüfungen', $body);
        $f = $this->get('/telc-pruefungszentrum/?kategorie=pflege-medizin')->body;
        self::assertStringContainsString('pflege-z9', $f);
        self::assertStringNotContainsString('/pruefungen/allgemein-z9/', $f);
    }

    public function testArchivedExamRedirectsToOverview(): void
    {
        $this->createExam('archiv-z9', 'archived');
        $r = $this->get('/pruefungen/archiv-z9/');
        self::assertSame(301, $r->status);
        self::assertSame('/telc-pruefungszentrum/', $r->headers['Location']);
        self::assertStringNotContainsString('archiv-z9', $this->get('/sitemap.xml')->body);
    }

    public function testFullSessionIsNotBookable(): void
    {
        $id = $this->createExam('voll-z9');
        $sid = $this->createSession($id, 2);
        $this->book($id, $sid, 'confirmed');
        $future = (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->book($id, $sid, 'reserved', $future);

        $r = $this->get('/pruefungen/voll-z9/');
        self::assertStringNotContainsString("/buchen/$sid/", $r->body);
        self::assertStringContainsString('Ausgebucht', $r->body);
        self::assertSame(303, $this->get("/pruefungen/voll-z9/buchen/$sid/")->status);
    }

    public function testExpiredReservationFreesTheSeat(): void
    {
        $id = $this->createExam('frei-z9');
        $sid = $this->createSession($id, 1);
        $past = (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->book($id, $sid, 'reserved', $past);
        $this->book($id, $sid, 'cancelled');
        self::assertStringContainsString("/pruefungen/frei-z9/buchen/$sid/", $this->get('/pruefungen/frei-z9/')->body);
        self::assertSame(200, $this->get("/pruefungen/frei-z9/buchen/$sid/")->status);
    }

    public function testDeadlinePassedIsNotBookable(): void
    {
        $id = $this->createExam('frist-z9');
        $sid = $this->createSession($id, 10, '+5 days', '-1 day');
        self::assertStringNotContainsString("/buchen/$sid/", $this->get('/pruefungen/frist-z9/')->body);
    }

    public function testExamWithoutPriceShowsNoInventedPrice(): void
    {
        $this->createExam('ohnepreis-z9', 'published', null);
        $r = $this->get('/pruefungen/ohnepreis-z9/');
        self::assertSame(200, $r->status);
        self::assertDoesNotMatchRegularExpression('/\d+,\d\d\s?€/u', $r->body);
    }

    public function testSessionOfOtherExamCannotBeBookedViaWrongSlug(): void
    {
        $a = $this->createExam('a-z9');
        $this->createExam('b-z9');
        $sid = $this->createSession($a);
        self::assertSame(404, $this->get("/pruefungen/b-z9/buchen/$sid/")->status);
    }
}
