<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Builder\PageService;

/** Seitenbaukasten-Seiten unter einem Bereich (Adresse „bereich/seite“) und die Bausteine „Symbol-Box“ und „Karte mit Symbol“. */
final class NestedPagesTest extends IntegrationTestCase
{
    private function newPage(string $slug, string $status = 'draft'): int
    {
        $r = $this->adminPost('/verwaltung/seiten/neu/', ['title' => 'Unterseite ' . $slug, 'slug' => $slug, 'status' => $status, 'seo_title' => '', 'seo_description' => '']);
        self::assertSame(303, $r->status, substr((string) $r->body, 0, 400));
        return $this->idFromLocation($r);
    }

    public function testNestedPageIsServedUnderItsPathWithNewBlocks(): void
    {
        $this->loginAs('editor');
        $id = $this->newPage('testbereich/unterseite');
        $tree = [
            ['id' => 'hero0001', 'type' => 'page_hero', 'f' => ['title' => 'Unterseite Titel']],
            ['id' => 'vis00001', 'type' => 'section_visual', 'f' => ['eyebrow' => 'Lernform', 'title' => 'Gemeinsam üben', 'text' => 'Text im Abschnitt', 'icon' => 'users', 'bg' => 'alt',
                'points' => [['icon' => 'users', 'title' => 'Gruppe', 'text' => '10 bis 15 Teilnehmende'], ['icon' => 'check', 'title' => 'Einzelbegleitung', 'text' => '']]]],
            ['id' => 'sec00001', 'type' => 'section', 'f' => [], 'c' => [
                ['id' => 'crd00001', 'type' => 'cards', 'f' => ['cols' => '2', 'look' => 'symbol', 'level' => 'h3', 'items' => [
                    ['icon' => 'target', 'media' => null, 'title' => 'Karte eins', 'text' => 'Text eins', 'link_label' => '', 'url' => '']]]],
            ]],
        ];
        $rev = (int) self::db()->value('SELECT draft_rev FROM pages WHERE id = ?', [$id]);
        $r = $this->adminPost('/verwaltung/seiten/' . $id . '/veroeffentlichen/', ['tree' => json_encode($tree), 'rev' => (string) $rev]);
        self::assertSame(200, $r->status, $r->body);

        $res = $this->get('/testbereich/unterseite/');
        self::assertSame(200, $res->status);
        self::assertStringContainsString('class="container sec-split"', $res->body);
        self::assertStringContainsString('sec-visual__medal', $res->body);
        self::assertStringContainsString('<b>Gruppe</b><small>10 bis 15 Teilnehmende</small>', $res->body);
        self::assertStringContainsString('class="card card--feature reveal"', $res->body);
        self::assertStringContainsString('Karte eins', $res->body);
        self::assertSame('/testbereich/unterseite/', PageService::url(self::db()->one('SELECT * FROM pages WHERE id = ?', [$id])));
        self::assertStringContainsString('/testbereich/unterseite/', $this->get('/sitemap.xml')->body);
    }

    public function testNestedSlugValidation(): void
    {
        $this->loginAs('editor');
        $post = fn(string $slug) => $this->adminPost('/verwaltung/seiten/neu/', ['title' => 'T', 'slug' => $slug, 'status' => 'draft', 'seo_title' => '', 'seo_description' => '']);
        self::assertSame(422, $post('verwaltung/test')->status, 'reservierter Bereich');
        self::assertSame(422, $post('a/b/c')->status, 'nur eine Ebene');
        self::assertSame(422, $post('/a')->status);
        self::assertSame(422, $post('Gross/klein')->status);
        $child = self::db()->one('SELECT c.slug AS c, p.slug AS p FROM services c JOIN services p ON p.id = c.parent_id LIMIT 1');
        if ($child !== null) {
            self::assertSame(422, $post($child['p'] . '/' . $child['c'])->status, 'gehört schon einer Leistung');
        }
        self::assertSame(303, $post('bereich-x/seite-y')->status);
        self::assertSame(422, $post('bereich-x/seite-y')->status, 'doppelt');
    }

    public function testUnknownNestedPathIsNotFound(): void
    {
        self::assertSame(404, $this->get('/gibt-es/nicht/')->status);
    }
}
