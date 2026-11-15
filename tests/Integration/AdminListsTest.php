<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Verwaltung: Baum-Listen (Menüs, Leistungen), Ausblenden nicht gefundener Adressen, Standortfoto in der Karte. */
final class AdminListsTest extends IntegrationTestCase
{
    public function testMenuListShowsSubitemsIndentedUnderParent(): void
    {
        $this->loginAs('admin');
        $db = self::db();
        $menu = (int) $db->value('SELECT id FROM menus ORDER BY id LIMIT 1');
        $db->run("INSERT INTO menu_items (menu_id, parent_id, label, url, sort_order) VALUES (?, NULL, 'Testgruppe', '/tg/', 99)", [$menu]);
        $parent = (int) $db->value("SELECT id FROM menu_items WHERE label = 'Testgruppe'");
        $db->run("INSERT INTO menu_items (menu_id, parent_id, label, url, sort_order) VALUES (?, ?, 'Testkind', '/tg/kind/', 1)", [$menu, $parent]);
        $body = $this->get('/verwaltung/menues/')->body;
        $p = strpos($body, 'Testgruppe');
        $c = strpos($body, 'Testkind');
        self::assertNotFalse($p);
        self::assertNotFalse($c);
        self::assertGreaterThan($p, $c, 'Untereintrag steht nach dem Haupteintrag');
        self::assertStringContainsString('tbl__branch', $body);
        self::assertStringContainsString('tbl__group', $body);
    }

    public function testServiceListIsNestedAndGroupsArchived(): void
    {
        $this->loginAs('admin');
        $body = $this->get('/verwaltung/leistungen/')->body;
        self::assertSame(200, $this->get('/verwaltung/leistungen/')->status);
        self::assertStringContainsString('tbl__root', $body);
        $parents = (int) self::db()->value('SELECT COUNT(*) FROM services WHERE parent_id IS NOT NULL');
        if ($parents > 0) {
            self::assertStringContainsString('tbl__branch', $body);
        }
    }

    public function testNotFoundAddressesCanBeHidden(): void
    {
        $this->loginAs('admin');
        $db = self::db();
        $db->run('DELETE FROM not_found_log');
        $db->run("INSERT INTO not_found_log (path, hits) VALUES ('/bot-eins.js', 2), ('/bot-zwei.js', 3)");
        self::assertStringContainsString('/bot-eins.js', $this->get('/verwaltung/weiterleitungen/')->body);

        $res = $this->adminPost('/verwaltung/weiterleitungen/nicht-gefunden/ausblenden/', ['path' => '/bot-eins.js']);
        self::assertSame(303, $res->status);
        $body = $this->get('/verwaltung/weiterleitungen/')->body;
        self::assertStringNotContainsString('/bot-eins.js', $body);
        self::assertStringContainsString('/bot-zwei.js', $body);

        $this->adminPost('/verwaltung/weiterleitungen/nicht-gefunden/alle-ausblenden/', []);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM not_found_log WHERE resolved_at IS NULL'));

        // erneuter Aufruf der Adresse bringt den Eintrag zurück
        $this->get('/bot-zwei.js');
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM not_found_log WHERE path = '/bot-zwei.js' AND resolved_at IS NULL"));
    }

    public function testLocationPhotoAppearsOnOverviewCard(): void
    {
        $db = self::db();
        $media = (int) $db->value('SELECT id FROM media ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $media);
        $loc = $db->one('SELECT id, slug FROM locations WHERE is_active = 1 ORDER BY id LIMIT 1');
        $db->run('UPDATE locations SET image_media_id = ? WHERE id = ?', [$media, $loc['id']]);
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        self::assertStringContainsString('location-card__photo', $this->get('/standorte/')->body);
        $db->run('UPDATE locations SET image_media_id = NULL WHERE id = ?', [$loc['id']]);
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        self::assertStringNotContainsString('location-card__photo', $this->get('/standorte/')->body);
    }

    public function testSavingServiceKeepsSectionsAndAllowsEditingThem(): void
    {
        $this->loginAs('admin');
        $db = self::db();
        $facts = ['slogan' => 'Mein Spruch', 'highlights' => ['A', 'B'], 'sections' => [
            ['eyebrow' => 'Ziel', 'title' => 'Titel eins', 'text' => 'Text eins', 'cards' => [['title' => 'K1', 'text' => 'T1'], ['title' => 'K2', 'text' => 'T2']]],
            ['eyebrow' => 'Form', 'title' => 'Titel zwei', 'text' => 'Text zwei', 'icon' => 'users'],
        ], 'faq_items' => [['q' => 'Frage?', 'a' => 'Antwort.']], 'cta_title' => 'Los', 'process_title' => 'Ablauf'];
        $id = $db->insert('services', ['slug' => 'testbereich', 'title' => 'Testbereich', 'teaser' => 'Kurz', 'status' => 'published', 'sort_order' => 50,
            'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE)]);

        // Formular zeigt die Abschnitte an
        $form = $this->get('/verwaltung/leistungen/' . $id . '/')->body;
        self::assertStringContainsString('Ziel | Titel eins | Text eins', $form);
        self::assertStringContainsString('1 | K1 | T1', $form);

        // Speichern ohne Änderung an den Abschnitten verliert nichts
        $post = ['title' => 'Testbereich', 'slug' => 'testbereich', 'status' => 'published', 'sort_order' => '50', 'teaser' => 'Kurz', '_version' => (string) $db->value('SELECT updated_at FROM services WHERE id = ?', [$id]),
            'facts_sections' => "Ziel | Titel eins | Text eins\nForm | Titel zwei neu | Text zwei",
            'facts_cards' => "1 | K1 | T1\n1 | K2 neu | T2",
            'facts_highlights' => "A\nB\nC", 'facts_faq' => 'Frage? | Antwort.', 'facts_cta_title' => 'Los', 'facts_process_title' => 'Ablauf'];
        $res = $this->adminPost('/verwaltung/leistungen/' . $id . '/', $post);
        self::assertSame(303, $res->status, substr((string) $res->body, 0, 400));
        $saved = (array) json_decode((string) $db->value('SELECT facts_json FROM services WHERE id = ?', [$id]), true);
        self::assertSame('Mein Spruch', $saved['slogan'], 'nicht bearbeitbare Angaben bleiben erhalten');
        self::assertSame('Titel zwei neu', $saved['sections'][1]['title']);
        self::assertSame('users', $saved['sections'][1]['icon'], 'Symbol des Abschnitts bleibt erhalten');
        self::assertSame('K2 neu', $saved['sections'][0]['cards'][1]['title']);
        self::assertSame(['A', 'B', 'C'], $saved['highlights']);
        self::assertSame('Antwort.', $saved['faq_items'][0]['a']);
    }

    public function testServiceSectionLinesAreValidated(): void
    {
        $this->loginAs('admin');
        $db = self::db();
        $id = $db->insert('services', ['slug' => 'testbereich2', 'title' => 'Testbereich2', 'teaser' => 'Kurz', 'status' => 'draft', 'sort_order' => 51]);
        $post = ['title' => 'Testbereich2', 'slug' => 'testbereich2', 'status' => 'draft', 'sort_order' => '51', 'teaser' => 'Kurz',
            'facts_sections' => 'Nur Titel', 'facts_cards' => '9 | A | B'];
        self::assertSame(422, $this->adminPost('/verwaltung/leistungen/' . $id . '/', $post)->status);
    }

    public function testReferenceRestoreOnlyFillsServicesWithoutSections(): void
    {
        $db = self::db();
        $lost = (int) $db->value("SELECT id FROM services WHERE slug = 'bildungszentrum' AND parent_id IS NULL");
        $kept = (int) $db->value("SELECT id FROM services WHERE slug = 'recruiting' AND parent_id IS NULL");
        $db->run("UPDATE services SET facts_json = '{\"slogan\":\"Alt\"}' WHERE id = ?", [$lost]);
        $db->run("UPDATE services SET facts_json = JSON_SET(facts_json, '$.sections[0].title', 'Eigene Änderung') WHERE id = ?", [$kept]);
        (new \Mea\Install\ReferenceStructure($db))->content(true);
        $a = (array) json_decode((string) $db->value('SELECT facts_json FROM services WHERE id = ?', [$lost]), true);
        self::assertNotEmpty($a['sections'], 'verlorene Abschnitte kommen zurück');
        $b = (array) json_decode((string) $db->value('SELECT facts_json FROM services WHERE id = ?', [$kept]), true);
        self::assertSame('Eigene Änderung', $b['sections'][0]['title'], 'Änderungen der Redaktion bleiben');
    }

    public function testFooterHasNoOpeningHoursAndBreaksBuildingOntoOwnLine(): void
    {
        $html = $this->get('/')->body;
        $footer = substr($html, (int) strpos($html, '<footer'));
        self::assertStringNotContainsString('Öffnungszeiten', $footer);
        self::assertMatchesRegularExpression('#Wilhelmstraße 98b<br><span class="nowrap">Gebäude 012</span><br>44649 Herne#', $footer);
    }
}
