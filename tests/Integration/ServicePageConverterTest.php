<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Builder\PageService;
use Mea\Module\Builder\ServicePageConverter;

/** Umwandler Leistung → Seitenbaukasten: gleiche Inhalte in gleicher Reihenfolge wie die Leistungsseite. */
final class ServicePageConverterTest extends IntegrationTestCase
{
    private function main(string $html): string
    {
        self::assertMatchesRegularExpression('#<main[^>]*>(.*)</main>#s', $html);
        preg_match('#<main[^>]*>(.*)</main>#s', $html, $m);
        $h = preg_replace('/ b-[0-9a-f]{8}\b/', '', $m[1]) ?? $m[1];
        return preg_replace_callback('/class="([^"]*)"/', fn($x) => 'class="' . trim(preg_replace('/\s+/', ' ', $x[1]) ?? '') . '"', $h) ?? $h;
    }

    /** Sichtbarer Text in Lesereihenfolge. */
    private function text(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#s', '', $html) ?? '';
        $html = preg_replace('#<(?:h[1-6]|p|li|summary|a|span|b|small|div|section|details)\b#', "\n<", $html) ?? '';
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    }

    /** @return array<string,int> */
    private function counts(string $html): array
    {
        $out = [];
        foreach (['card--feature', 'class="steps"', 'sec-visual__medal', 'class="faq"', 'cta-band', '<h2', '<h3', 'class="eyebrow"', 'section--alt', 'service-card', 'grid--3', 'grid--2', '<details'] as $k) {
            $out[$k] = substr_count($html, $k);
        }
        return $out;
    }

    public function testConvertedPagesMatchTheServicePages(): void
    {
        $this->loginAs('editor');
        $db = self::db();
        $conv = new ServicePageConverter($this->app->content);
        $company = ['phone' => (string) ($this->app->settings->get('company', [])['phone'] ?? ''), 'phone_display' => (string) ($this->app->settings->get('company', [])['phone_display'] ?? '')];
        $converted = 0;
        $skipped = [];
        foreach ($db->all("SELECT * FROM services WHERE status = 'published' ORDER BY id") as $row) {
            $service = $this->app->content->decodeService($row);
            $r = $conv->convert($service, $company);
            $label = $r['slug'];
            if ($r['tree'] === null) {
                $skipped[$label] = $r['reason'];
                continue;
            }
            $converted++;
            $url = '/' . $r['slug'] . '/';
            $orig = $this->get($url);
            self::assertSame(200, $orig->status, $url);

            // Als Seite unter einer Testadresse veröffentlichen
            $test = 'paritaet-' . $converted;
            $id = $db->insert('pages', ['slug' => $test, 'title' => $service['title'], 'intro' => '', 'template' => 'default', 'status' => 'draft', 'is_system' => 0,
                'blocks_json' => '[]', 'draft_json' => json_encode($r['tree'], JSON_UNESCAPED_UNICODE)]);
            $rev = (int) $db->value('SELECT draft_rev FROM pages WHERE id = ?', [$id]);
            $save = $this->adminPost('/verwaltung/seiten/' . $id . '/veroeffentlichen/', ['tree' => json_encode($r['tree']), 'rev' => (string) $rev]);
            self::assertSame(200, $save->status, $label . ': ' . $save->body);
            $page = $this->get('/' . $test . '/');
            self::assertSame(200, $page->status, $label);

            $a = $this->main($orig->body);
            $b = $this->main($page->body);
            $ta = $this->text($a);
            $tb = $this->text($b);
            if ($ta !== $tb) {
                $i = 0;
                while ($i < min(strlen($ta), strlen($tb)) && $ta[$i] === $tb[$i]) {
                    $i++;
                }
                self::fail($label . ': Text weicht ab bei ... ' . substr($ta, max(0, $i - 60), 200) . ' ||| ' . substr($tb, max(0, $i - 60), 200));
            }
            self::assertSame($this->counts($a), $this->counts($b), $label . ': Aufbau (Karten, Schritte, Boxen, Fragen)');
        }
        self::assertGreaterThanOrEqual(10, $converted, 'Die meisten Campus-Seiten sind umwandelbar; übersprungen: ' . json_encode($skipped, JSON_UNESCAPED_UNICODE));
    }

    public function testPickMatchesTheServiceTemplate(): void
    {
        $tpl = $this->app->view;
        foreach (['Sprache lernen', 'Förderung klären', 'Prüfung und Zertifikat', 'Ausbildung vorbereiten', 'Region Ruhrgebiet', 'Nächste Schritte planen',
            'Beratung im Gespräch', 'Gruppe oder Einzel', 'Etwas ganz anderes'] as $i => $text) {
            $viaTwig = trim($this->app->view->render('partials/_pick_probe.twig', ['t' => $text, 'i' => $i + 1]));
            self::assertSame($viaTwig, ServicePageConverter::pick($text, $i + 1), $text);
        }
        unset($tpl);
    }

    public function testCreatingPageFromServiceKeepsOldPageUntilPublishedThenReplacesIt(): void
    {
        $this->loginAs('admin');
        $db = self::db();
        $row = $db->one("SELECT id, slug, title FROM services WHERE slug = 'recruiting' AND status = 'published'");
        self::assertNotNull($row);
        $before = $this->get('/recruiting/')->body;

        $res = $this->adminPost('/verwaltung/leistungen/' . $row['id'] . '/als-seite/', []);
        self::assertSame(303, $res->status);
        $pid = (int) $db->value("SELECT id FROM pages WHERE slug = 'recruiting'");
        self::assertGreaterThan(0, $pid);
        self::assertSame($before, $this->get('/recruiting/')->body, 'Entwurf ändert die Live-Seite nicht');

        $tree = (string) $db->value('SELECT draft_json FROM pages WHERE id = ?', [$pid]);
        $rev = (int) $db->value('SELECT draft_rev FROM pages WHERE id = ?', [$pid]);
        self::assertSame(200, $this->adminPost('/verwaltung/seiten/' . $pid . '/veroeffentlichen/', ['tree' => $tree, 'rev' => (string) $rev])->status);
        $after = $this->get('/recruiting/');
        self::assertSame(200, $after->status);
        self::assertStringContainsString('Fachkräfte finden', $after->body);
        self::assertStringContainsString('class="section-head', $after->body);

        // zweiter Aufruf legt keine zweite Seite an
        $this->adminPost('/verwaltung/leistungen/' . $row['id'] . '/als-seite/', []);
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM pages WHERE slug = 'recruiting'"));
        self::assertSame(1, substr_count($this->get('/sitemap.xml')->body, '/recruiting/</loc>'));
    }
}
