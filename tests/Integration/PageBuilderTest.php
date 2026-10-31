<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Builder\PageService;

/**
 * Abnahme Seitenbaukasten: Startseite in der Seitenverwaltung, Übernahme der alten Seiten, Entwurf/Veröffentlichen,
 * Konflikte, Rechte, CSRF, XSS-Schutz, globale Bausteine, Kopf- und Fußbereich.
 */
final class PageBuilderTest extends IntegrationTestCase
{
    private function homeId(): int
    {
        return (int) self::db()->value('SELECT id FROM pages WHERE is_home = 1');
    }

    /** @return list<array<string,mixed>> */
    private function draftTree(int $id): array
    {
        return PageService::tree(self::db()->one('SELECT * FROM pages WHERE id = ?', [$id]), true);
    }

    private function rev(int $id): int
    {
        return (int) self::db()->value('SELECT draft_rev FROM pages WHERE id = ?', [$id]);
    }

    /** @param list<array<string,mixed>> $tree */
    private function save(int $id, array $tree, ?int $rev = null, string $action = 'speichern'): \Mea\Core\Response
    {
        return $this->adminPost('/verwaltung/seiten/' . $id . '/' . $action . '/', ['tree' => json_encode($tree), 'rev' => (string) ($rev ?? $this->rev($id))]);
    }

    private static function section(array $children, string $id = 'sec0001'): array
    {
        return ['id' => $id, 'type' => 'section', 'f' => [], 'c' => $children];
    }

    public function testHomePageExistsOnceIsListedFirstAndIsProtected(): void
    {
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM pages WHERE is_home = 1'), 'genau eine Startseite');
        $home = self::db()->one('SELECT * FROM pages WHERE is_home = 1');
        self::assertSame('1', (string) $home['is_system']);
        self::assertSame('published', $home['status']);
        self::assertNotEmpty($home['seo_title'], 'SEO aus den alten Einstellungen übernommen');

        $this->loginAs('editor');
        $list = $this->get('/verwaltung/seiten/');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('<span class="badge badge--success">Startseite</span>', $list->body);
        self::assertStringContainsString('/verwaltung/seiten/' . $home['id'] . '/editor/', $list->body);
        self::assertLessThan(strpos($list->body, '/agb/'), strpos($list->body, '<code>/</code>'), 'Startseite steht oben');

        // Löschen ist nicht möglich, Adresse und Status sind geschützt
        $this->adminPost('/verwaltung/seiten/' . $home['id'] . '/loeschen/', []);
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM pages WHERE is_home = 1'));
        $r = $this->adminPost('/verwaltung/seiten/' . $home['id'] . '/einstellungen/', ['title' => 'Start', 'slug' => 'anders', 'seo_title' => 'Neuer SEO-Titel', 'seo_description' => 'Beschreibung']);
        self::assertSame(200, $r->status, $r->body);
        $home2 = self::db()->one('SELECT * FROM pages WHERE is_home = 1');
        self::assertSame($home['slug'], $home2['slug'], 'Adresse der Startseite unveränderlich');
        self::assertSame('Neuer SEO-Titel', $home2['seo_title']);
        self::assertStringContainsString('<title>Neuer SEO-Titel</title>', $this->get('/')->body);

        // Die Übernahme läuft kein zweites Mal und legt keine zweite Startseite an
        (new \Mea\Module\Builder\LegacyImport(self::db()))->run();
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM pages WHERE is_home = 1'));
    }

    public function testAllFormerPagesAreBuilderPagesAndKeepTheirUrls(): void
    {
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM pages WHERE blocks_json IS NULL'), 'jede Seite hat einen Aufbau');
        foreach (['/', '/leistungen/', '/telc-pruefungszentrum/', '/preise/', '/standorte/', '/kontakt/', '/faq/', '/ueber-uns/', '/impressum/', '/datenschutz/', '/agb/', '/karriere/'] as $url) {
            $r = $this->get($url);
            self::assertSame(200, $r->status, $url);
            self::assertStringNotContainsString('data-b=', $r->body, 'keine Editor-Markierungen auf der Website: ' . $url);
            self::assertSame(1, substr_count($r->body, '<h1'), 'genau eine H1: ' . $url);
        }
        $r = $this->get('/startseite/');
        self::assertSame(301, $r->status);
        self::assertSame('/', $r->headers['Location']);
        self::assertStringNotContainsString('/startseite/', $this->get('/sitemap.xml')->body);
    }

    public function testDraftIsInvisibleUntilPublishedAndVersionsAreKept(): void
    {
        $this->loginAs('editor');
        $id = $this->homeId();
        $tree = $this->draftTree($id);
        $tree[] = self::section([['id' => 'head0001', 'type' => 'heading', 'f' => ['text' => 'Neu im Entwurf', 'level' => 'h2']]]);
        $r = $this->save($id, $tree);
        self::assertSame(200, $r->status, $r->body);
        self::assertTrue(json_decode($r->body, true)['ok']);

        self::assertStringNotContainsString('Neu im Entwurf', $this->get('/')->body, 'Entwurf ist nicht öffentlich');
        $preview = $this->get('/verwaltung/seiten/' . $id . '/entwurf-ansehen/');
        self::assertStringContainsString('Neu im Entwurf', $preview->body);
        self::assertStringContainsString('noindex', (string) ($preview->headers['X-Robots-Tag'] ?? ''));
        $_SESSION = [];
        self::assertSame(303, $this->get('/verwaltung/seiten/' . $id . '/entwurf-ansehen/')->status, 'Vorschau nur angemeldet');

        $this->loginAs('editor');
        $r = $this->save($id, $this->draftTree($id), null, 'veroeffentlichen');
        self::assertSame(200, $r->status, $r->body);
        $html = $this->get('/')->body;
        self::assertStringContainsString('Neu im Entwurf', $html);
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="/media/design/seite-' . $id . '-[0-9a-f]{16}\.css">#', $html);
        self::assertNull(self::db()->value('SELECT draft_json FROM pages WHERE id = ?', [$id]));
        $kinds = self::db()->all("SELECT kind FROM content_revisions WHERE entity_type = 'page_builder' AND entity_id = ? ORDER BY id", [$id]);
        self::assertSame(['draft', 'published'], array_slice(array_column($kinds, 'kind'), -2));

        $versions = json_decode($this->get('/verwaltung/seiten/' . $id . '/versionen.json')->body, true);
        self::assertNotEmpty($versions);
        $v = json_decode($this->get('/verwaltung/seiten/' . $id . '/versionen/' . $versions[0]['id'] . '.json')->body, true);
        self::assertIsArray($v['tree']);
    }

    public function testConcurrentSaveIsDetected(): void
    {
        $this->loginAs('editor');
        $id = $this->homeId();
        $rev = $this->rev($id);
        $tree = $this->draftTree($id);
        self::assertSame(200, $this->save($id, $tree, $rev)->status);
        $r = $this->save($id, $tree, $rev);
        self::assertSame(409, $r->status, 'zweites Speichern auf altem Stand');
        $j = json_decode($r->body, true);
        self::assertTrue($j['conflict']);
        self::assertSame('Test Editor', $j['by']);
        $r = $this->adminPost('/verwaltung/seiten/' . $id . '/speichern/', ['tree' => json_encode($tree), 'rev' => (string) $rev, 'force' => '1']);
        self::assertSame(200, $r->status, 'bewusst überschreiben');
    }

    public function testUnsafeContentIsCleanedOrRejectedServerSide(): void
    {
        $this->loginAs('editor');
        $id = $this->homeId();
        $tree = [
            self::section([
                ['id' => 'xss00001', 'type' => 'heading', 'f' => ['text' => '<script>alert(1)</script>Titel', 'level' => 'h1']],
                ['id' => 'xss00002', 'type' => 'text', 'f' => ['html' => '<p onclick="x()">Hallo <a href="javascript:alert(1)">Link</a><img src=x onerror=alert(1)><iframe src="//evil"></iframe></p>']],
                ['id' => 'xss00003', 'type' => 'buttons', 'c' => [['id' => 'xss00004', 'type' => 'button', 'f' => ['label' => 'Los', 'url' => 'javascript:alert(1)']]]],
                ['id' => 'xss00005', 'type' => 'section', 'c' => []],
                ['id' => 'xss00006', 'type' => 'erfunden'],
                ['id' => 'xss00007', 'type' => 'heading', 'f' => ['text' => 'x', 'level' => 'h9', 'anchor' => 'Ungültig Anker"']],
            ]),
            ['id' => 'col00001', 'type' => 'column', 'c' => []],
            ['id' => 'sty00001', 'type' => 'section', 's' => ['d' => ['color' => 'red;background:url(x)', 'pt' => 99999, 'font' => 'Comic"; x', 'bg' => 'var:../evil']], 'c' => []],
        ];
        $r = $this->save($id, $tree);
        self::assertSame(200, $r->status, $r->body);
        $j = json_decode($r->body, true);
        $json = json_encode($j['tree']);
        self::assertStringNotContainsString('script', $json);
        self::assertStringNotContainsString('javascript', $json);
        self::assertStringNotContainsString('onclick', $json);
        self::assertStringNotContainsString('onerror', $json);
        self::assertStringNotContainsString('iframe', $json);
        self::assertStringNotContainsString('erfunden', $json);
        self::assertStringNotContainsString('col00001', $json, 'Spalte nur in Spalten');
        self::assertStringNotContainsString('xss00005', $json, 'kein Abschnitt im Abschnitt');
        self::assertStringNotContainsString('red;', $json);
        self::assertStringNotContainsString('evil', $json);
        self::assertNotEmpty($j['errors'], 'Hinweise zu den entfernten Teilen');
        $h = $j['tree'][0]['c'];
        self::assertSame('h2', $h[3]['f']['level'], 'unbekannte Ebene → Standard');
        self::assertSame('', $h[3]['f']['anchor']);
        $css = $this->app->pages()->cssFor($j['tree'], true);
        self::assertStringNotContainsString('url(', $css);
        self::assertStringNotContainsString('99999', $css, 'Werte außerhalb des Bereichs werden verworfen');
        self::assertStringNotContainsString('sty00001', json_encode(array_filter($j['tree'], fn($n) => !empty($n['s']))), 'kein ungültiger Stil übrig');

        $preview = $this->get('/verwaltung/seiten/' . $id . '/entwurf-ansehen/')->body;
        self::assertStringNotContainsString('<script>alert', $preview);
        self::assertStringNotContainsString('javascript:', $preview);

        $bad = $this->adminPost('/verwaltung/seiten/' . $id . '/speichern/', ['tree' => '{kaputt', 'rev' => (string) $this->rev($id)]);
        self::assertSame(422, $bad->status);
    }

    public function testRolesAndCsrfAreCheckedOnTheServer(): void
    {
        $id = $this->homeId();
        $tree = $this->draftTree($id);
        // Autorin: Entwürfe ja, veröffentlichen und globale Bausteine nein
        $this->loginAs('author');
        self::assertSame(200, $this->get('/verwaltung/seiten/' . $id . '/editor/')->status);
        self::assertSame(200, $this->save($id, $tree)->status);
        self::assertSame(403, $this->save($id, $tree, null, 'veroeffentlichen')->status);
        self::assertSame(403, $this->get('/verwaltung/vorlagentexte/')->status);
        self::assertSame(403, $this->adminPost('/verwaltung/bausteine/neu/', ['name' => 'X', 'tree' => '[]'])->status);
        $editor = $this->get('/verwaltung/seiten/' . $id . '/editor/')->body;
        self::assertStringNotContainsString('data-publish', $editor, 'kein Veröffentlichen-Button ohne Recht');
        // Buchungsrolle: kein Zugang
        $this->loginAs('booking');
        self::assertSame(403, $this->get('/verwaltung/seiten/' . $id . '/editor/')->status);
        self::assertSame(403, $this->save($id, $tree)->status);
        // CSRF
        $this->loginAs('admin');
        $r = $this->request('POST', '/verwaltung/seiten/' . $id . '/speichern/', ['tree' => json_encode($tree), 'rev' => (string) $this->rev($id)]);
        self::assertSame(400, $r->status);
    }

    public function testCanvasMayOnlyBeFramedByTheEditor(): void
    {
        $this->loginAs('editor');
        $id = $this->homeId();
        $canvas = $this->get('/verwaltung/seiten/' . $id . '/leinwand/');
        self::assertSame(200, $canvas->status);
        self::assertStringContainsString('data-b=', $canvas->body);
        self::assertStringContainsString('builder-canvas.css', $canvas->body);
        self::assertSame('SAMEORIGIN', $canvas->headers['X-Frame-Options']);
        self::assertStringContainsString("frame-ancestors 'self'", $canvas->headers['Content-Security-Policy']);
        self::assertSame('DENY', $this->get('/verwaltung/seiten/' . $id . '/editor/')->headers['X-Frame-Options']);
        self::assertSame('DENY', $this->get('/')->headers['X-Frame-Options']);

        $r = $this->adminPost('/verwaltung/seiten/' . $id . '/darstellen/', ['tree' => json_encode([self::section([['id' => 'hh000001', 'type' => 'heading', 'f' => ['text' => 'Live']]])])]);
        $j = json_decode($r->body, true);
        self::assertStringContainsString('Live', $j['html']);
        self::assertStringContainsString('data-b="hh000001"', $j['html']);
        self::assertStringNotContainsString('Live', (string) self::db()->value('SELECT draft_json FROM pages WHERE id = ?', [$id]), 'Darstellen speichert nichts');
    }

    public function testGlobalComponentIsSharedAndProtected(): void
    {
        $this->loginAs('editor');
        $r = $this->adminPost('/verwaltung/bausteine/neu/', ['name' => 'Kontakt-Aufruf', 'tree' => json_encode([['id' => 'cta00001', 'type' => 'cta', 'f' => ['title' => 'Global gesagt']]])]);
        $cid = (int) json_decode($r->body, true)['id'];
        self::assertGreaterThan(0, $cid);
        $id = $this->homeId();
        $tree = $this->draftTree($id);
        $tree[] = ['id' => 'ref00001', 'type' => 'component', 'f' => ['ref' => $cid]];
        $this->save($id, $tree, null, 'veroeffentlichen');
        self::assertStringContainsString('Global gesagt', $this->get('/')->body);

        // Änderung am Baustein wirkt nach dem Veröffentlichen auf der Seite
        $ctree = [['id' => 'cta00001', 'type' => 'cta', 'f' => ['title' => 'Überall geändert']]];
        $r = $this->adminPost('/verwaltung/bausteine/' . $cid . '/veroeffentlichen/', ['tree' => json_encode($ctree), 'rev' => '0']);
        self::assertSame(200, $r->status, $r->body);
        $html = $this->get('/')->body;
        self::assertStringContainsString('Überall geändert', $html);
        self::assertStringNotContainsString('Global gesagt', $html);

        // Löschen nur, wenn nirgends verwendet
        $this->adminPost('/verwaltung/bausteine/' . $cid . '/loeschen/', []);
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM builder_components WHERE id = ?', [$cid]));
        // Verschachtelte globale Bausteine sind nicht erlaubt
        $r = $this->adminPost('/verwaltung/bausteine/neu/', ['name' => 'Verschachtelt', 'tree' => json_encode([['id' => 'ref00002', 'type' => 'component', 'f' => ['ref' => $cid]]])]);
        self::assertSame(422, $r->status);
    }

    public function testHeaderAndFooterTextsAreEditableAndValidated(): void
    {
        $this->loginAs('editor');
        $r = $this->adminPost('/verwaltung/vorlagentexte/', ['t' => ['header.cta_label' => 'Jetzt anmelden', 'footer.company_heading' => 'Über MEA', 'quickbar.cta_url' => 'javascript:alert(1)']]);
        self::assertSame(422, $r->status);
        $r = $this->adminPost('/verwaltung/vorlagentexte/', ['t' => ['header.cta_label' => 'Jetzt <b>anmelden</b>', 'footer.company_heading' => 'Über MEA']]);
        self::assertSame(303, $r->status);
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        $html = $this->get('/kontakt/')->body;
        self::assertStringContainsString('>Jetzt anmelden</a>', $html);
        self::assertStringContainsString('<h2>Über MEA</h2>', $html);
        self::assertStringContainsString('Prüfung buchen', $html, 'nicht geänderte Texte bleiben beim Standard');
    }

    public function testMediaUsedInBuilderIsReported(): void
    {
        $media = (int) self::db()->value("SELECT id FROM media WHERE kind = 'image' ORDER BY id LIMIT 1");
        $this->loginAs('editor');
        $id = $this->homeId();
        $tree = $this->draftTree($id);
        $tree[] = self::section([['id' => 'img00001', 'type' => 'image', 'f' => ['media' => $media, 'alt' => 'Test']]], 'secimg01');
        $this->save($id, $tree);
        $usages = (new \Mea\Admin\MediaController($this->app))->usages($media);
        self::assertContains('Seite: Startseite (Entwurf)', array_column($usages, 'label'));
    }

    public function testNewPageGetsABlankBuilderLayout(): void
    {
        $this->loginAs('editor');
        $r = $this->adminPost('/verwaltung/seiten/neu/', ['title' => 'Neue Testseite', 'slug' => 'neue-testseite', 'status' => 'draft', 'seo_title' => '', 'seo_description' => '']);
        $id = $this->idFromLocation($r);
        $page = self::db()->one('SELECT * FROM pages WHERE id = ?', [$id]);
        self::assertNull($page['is_home']);
        self::assertNotEmpty(PageService::tree($page, true));
        self::assertSame(404, $this->get('/neue-testseite/')->status, 'Entwurf nicht öffentlich');
        $r = $this->adminPost('/verwaltung/seiten/' . $id . '/duplizieren/', []);
        self::assertSame(303, $r->status);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM pages WHERE slug = 'neue-testseite-kopie' AND status = 'draft'"));
    }

    public function testStylesPerDeviceUseDesignTokensAndBreakpoints(): void
    {
        $tree = [['id' => 'stl00001', 'type' => 'section', 'c' => [], 's' => [
            'd' => ['pt' => 40, 'color' => 'var:color-primary', 'radius' => 12],
            't' => ['pt' => 24],
            'm' => ['pt' => 8, 'hide' => true],
        ]]];
        [$clean, $errors] = $this->app->pages()->validator()->validate($tree);
        self::assertSame([], $errors);
        $css = $this->app->pages()->cssFor($clean, true);
        self::assertStringContainsString('.b-stl00001.b-stl00001 {', $css);
        self::assertStringContainsString('padding-top: 40px', $css);
        self::assertStringContainsString('color: var(--color-primary)', $css);
        self::assertMatchesRegularExpression('#@media \(max-width: 900px\) \{ \.b-stl00001\.b-stl00001 \{[^}]*padding-top: 24px#', $css);
        self::assertMatchesRegularExpression('#@media \(max-width: 600px\) \{ \.b-stl00001\.b-stl00001 \{[^}]*padding-top: 8px#', $css);
        self::assertStringContainsString('display: none !important', $css);
    }

    public function testPagesAreTakenOverOnFirstUseAfterAnUpdate(): void
    {
        // Zustand direkt nach der Migration eines Updates: Tabellen da, Seiten noch ohne Aufbau, keine Startseite
        self::db()->run('DELETE FROM pages WHERE is_home = 1');
        self::db()->run('UPDATE pages SET blocks_json = NULL, draft_json = NULL');
        $app = new \Mea\Core\App(self::root(), self::config(), self::db());
        $app->useDesignDir(sys_get_temp_dir() . '/mea-test-design-' . getmypid() . '/design');
        $app->pages();
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM pages WHERE is_home = 1'));
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM pages WHERE blocks_json IS NULL'));
        self::assertSame([], \Mea\Module\Builder\LegacyImport::ensure(self::db()), 'zweiter Aufruf tut nichts');
    }
}
