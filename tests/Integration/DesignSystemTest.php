<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\App;
use Mea\Core\Request;
use Mea\Module\Design\DesignSchema;

/** Abnahme Designsystem: speichern, ausliefern, Cache, Rechte, Prüfung, Vorschau, Zurücksetzen, eigene Schriften. */
final class DesignSystemTest extends IntegrationTestCase
{
    /** Alle Felder mit Standardwerten, wie das Formular sie schickt. @return array<string,string> */
    private static function form(array $changes = []): array
    {
        $out = [];
        foreach (DesignSchema::defaults() as $k => $v) {
            $out[$k] = (string) $v;
        }
        return array_merge($out, $changes);
    }

    private function cssOf(string $html): string
    {
        self::assertMatchesRegularExpression('#<link rel="stylesheet" href="(/media/design/design-[0-9a-f]{16}\.css)">#', $html);
        preg_match('#href="/media/design/(design-[0-9a-f]{16}\.css)"#', $html, $m);
        $file = $this->app->designDir() . '/' . $m[1];
        self::assertFileExists($file);
        return (string) file_get_contents($file);
    }

    public function testEveryPageLoadsTheGeneratedVariablesBeforeTheComponents(): void
    {
        $html = $this->get('/')->body;
        $css = $this->cssOf($html);
        self::assertStringContainsString('--color-primary: #3f8d7b;', $css);
        self::assertStringContainsString('--font-family-heading: "Plus Jakarta Sans"', $css);
        self::assertStringContainsString('--font-size-h1: clamp(', $css);
        self::assertStringContainsString('@font-face { font-family: "Inter"', $css);
        self::assertLessThan(strpos($html, 'css/app.css'), strpos($html, '/media/design/design-'), 'Variablen vor den Komponenten laden');
        self::assertStringContainsString('<meta name="theme-color" content="#0e3042">', $html);
        self::assertStringContainsString('rel="preload" href="/assets/fonts/plus-jakarta-sans-latin-wght-normal.woff2"', $html);
        // Unterseiten und Prüfungsseite (Produktdetail) nutzen dieselbe Datei
        foreach (['/kontakt/', '/telc-pruefungszentrum/', '/leistungen/'] as $p) {
            self::assertStringContainsString($this->app->design()->stylesheetUrl(), $this->get($p)->body, $p);
        }
    }

    public function testOnlyAdministratorsMayChangeTheDesign(): void
    {
        foreach (['editor', 'booking'] as $role) {
            $this->loginAs($role);
            self::assertSame(403, $this->get('/verwaltung/designsystem/')->status, $role);
            self::assertSame(403, $this->adminPost('/verwaltung/designsystem/', self::form(['color_primary' => '#ff0000']))->status, $role);
            self::assertSame(403, $this->adminPost('/verwaltung/designsystem/zuruecksetzen/', [])->status, $role);
        }
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM design_tokens'));
        $_SESSION = [];
        self::assertSame(303, $this->get('/verwaltung/designsystem/')->status, 'ohne Anmeldung zur Anmeldeseite');

        $this->loginAs('admin');
        $page = $this->get('/verwaltung/designsystem/');
        self::assertSame(200, $page->status);
        foreach (['Typografie', 'Farben', 'Layout', 'Komponenten', 'Responsive', 'type="color"', 'data-ds-frame', 'Alles auf Standard zurücksetzen', 'Eigene Schriften'] as $s) {
            self::assertStringContainsString($s, $page->body);
        }
        self::assertStringContainsString('href="/verwaltung/designsystem/"', $page->body, 'Menüpunkt');
    }

    public function testSavedValuesReachTheWholeSiteAndSurviveReloadAndNewLogin(): void
    {
        $this->loginAs('admin');
        $oldUrl = $this->app->design()->stylesheetUrl();
        $r = $this->adminPost('/verwaltung/designsystem/', self::form([
            'color_primary' => '#AA3355', 'font_heading' => 'lora', 'size_h1' => '50', 'size_h1_mobile' => '30',
            'radius_card' => '4', 'space_scale' => '1,2', 'tt_button' => 'uppercase', '_tab' => 'colors', 'unbekannt' => 'x',
        ]));
        self::assertSame(303, $r->status, substr($r->body, 0, 400));
        self::assertSame('/verwaltung/designsystem/#reiter-colors', $r->headers['Location']);
        // Nur Abweichungen vom Standard sind gespeichert
        $rows = array_column(self::db()->all('SELECT token_key, value_json FROM design_tokens ORDER BY token_key'), 'value_json', 'token_key');
        self::assertSame(['color_primary', 'font_heading', 'radius_card', 'size_h1', 'size_h1_mobile', 'space_scale', 'tt_button'], array_keys($rows));
        self::assertSame('"#aa3355"', $rows['color_primary']);
        self::assertNotNull(self::db()->value("SELECT 1 FROM audit_log WHERE action = 'design.updated'"));

        // Neuer Seitenaufruf mit frischer Anwendung (wie nach Neuladen oder erneutem Login)
        $app = new App(self::root(), self::config(), self::db());
        $app->useDesignDir($this->app->designDir());
        $this->app = $app;
        $_SESSION = [];
        $html = $this->get('/')->body;
        self::assertStringNotContainsString($oldUrl, $html, 'neue Datei, damit Browser-Caches nicht greifen');
        $css = $this->cssOf($html);
        self::assertStringContainsString('--color-primary: #aa3355;', $css);
        self::assertStringContainsString('--font-family-heading: "Lora", Georgia', $css);
        self::assertStringContainsString('--font-size-h1: clamp(1.875rem, ', $css);
        self::assertStringContainsString(', 3.125rem);', $css);
        self::assertStringContainsString('--radius-card: 4px;', $css);
        self::assertStringContainsString('--space-scale: 1.2;', $css);
        self::assertStringContainsString('--text-transform-button: uppercase;', $css);
        self::assertStringContainsString('rel="preload" href="/assets/fonts/lora-latin-wght-normal.woff2"', $html);
        self::assertStringNotContainsString('unbekannt', $css);

        $this->loginAs('admin');
        $page = $this->get('/verwaltung/designsystem/')->body;
        self::assertMatchesRegularExpression('/id="d-color_primary" name="color_primary"[^>]*value="#aa3355"/', $page);
        self::assertStringContainsString('7 von ' . count(DesignSchema::fields()) . ' Werten geändert', $page);
    }

    /** @return iterable<string,array{string,string}> */
    public static function manipulated(): iterable
    {
        yield 'CSS-Einschleusung in Farbe' => ['color_primary', '#fff;}body{display:none'];
        yield 'Farbname statt HEX' => ['color_text', 'red'];
        yield 'url() in Zahl' => ['size_body', '16px;background:url(x)'];
        yield 'Zahl zu groß' => ['size_h1', '500'];
        yield 'negative Breite' => ['content_max_width', '-10'];
        yield 'Exponent' => ['lh_body', '1e3'];
        yield 'unbekannte Auswahl' => ['tt_heading', 'expression(alert(1))'];
        yield 'unbekannter Schatten' => ['shadow_card', '0 0 0 red'];
        yield 'unbekannte Schrift' => ['font_body', 'Comic Sans'];
        yield 'Gewicht außerhalb' => ['weight_body', '950'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('manipulated')]
    public function testInvalidOrManipulatedInputIsRejectedServerSide(string $key, string $value): void
    {
        $this->loginAs('admin');
        $r = $this->adminPost('/verwaltung/designsystem/', self::form([$key => $value]));
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Nicht gespeichert', $r->body);
        self::assertStringContainsString('id="d-' . $key . '-err" data-ds-error>', $r->body);
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM design_tokens'));
    }

    public function testArraysAndCrossFieldRulesAreRejected(): void
    {
        $this->loginAs('admin');
        self::assertSame(422, $this->adminPost('/verwaltung/designsystem/', array_merge(self::form(), ['color_primary' => ['#ffffff']]))->status);
        $r = $this->adminPost('/verwaltung/designsystem/', self::form(['color_focus' => '#F6F9F8']));
        self::assertSame(422, $r->status);
        self::assertStringContainsString('Der Fokusrahmen darf nicht dieselbe Farbe wie der Hintergrund haben.', $r->body);
        // Ohne gültiges CSRF-Token passiert nichts
        $r = $this->request('POST', '/verwaltung/designsystem/', self::form(['color_primary' => '#000000']));
        self::assertSame(400, $r->status);
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM design_tokens'));
    }

    public function testPreviewComputesVariablesWithoutSaving(): void
    {
        $this->loginAs('admin');
        $r = $this->adminPost('/verwaltung/designsystem/vorschau/', self::form(['color_button' => '#123456', 'size_body' => '18', 'size_body_mobile' => '18', 'radius_button' => 'abc']));
        self::assertSame(200, $r->status);
        $d = json_decode($r->body, true);
        self::assertFalse($d['ok']);
        self::assertSame('#123456', $d['vars']['--color-button']);
        self::assertSame('1.125rem', $d['vars']['--font-size-body']);
        self::assertSame('999px', $d['vars']['--radius-button'], 'fehlerhaftes Feld zeigt den gespeicherten Wert');
        self::assertArrayHasKey('radius_button', $d['errors']);
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM design_tokens'));
        // Weiße Schrift auf hellem Button: Hinweis zur Lesbarkeit
        $d = json_decode($this->adminPost('/verwaltung/designsystem/vorschau/', self::form(['color_button' => '#f0f0f0']))->body, true);
        self::assertTrue($d['ok']);
        self::assertNotEmpty(array_filter($d['warnings'], fn($w) => str_contains($w, 'Button-Schrift')));
        // Die Vorschau darf die Website im Rahmen zeigen, sonst bleibt Einbetten verboten
        self::assertSame('SAMEORIGIN', $this->get('/?designvorschau=1')->headers['X-Frame-Options']);
        self::assertStringContainsString("frame-ancestors 'self'", $this->get('/kontakt/?designvorschau=1')->headers['Content-Security-Policy']);
        self::assertSame('DENY', $this->get('/')->headers['X-Frame-Options']);
        self::assertSame('DENY', $this->get('/verwaltung/designsystem/?designvorschau=1')->headers['X-Frame-Options']);
    }

    public function testResetRestoresDefaultsAndCacheFile(): void
    {
        $this->loginAs('admin');
        $default = $this->app->design()->stylesheetUrl();
        $this->adminPost('/verwaltung/designsystem/', self::form(['color_text' => '#000000']));
        self::assertNotSame($default, $this->app->design()->stylesheetUrl());
        $r = $this->adminPost('/verwaltung/designsystem/zuruecksetzen/', []);
        self::assertSame(303, $r->status);
        self::assertSame(0, (int) self::db()->value('SELECT COUNT(*) FROM design_tokens'));
        self::assertSame($default, $this->app->design()->stylesheetUrl(), 'gleiche Werte, gleiche Datei');
        self::assertNotNull(self::db()->value("SELECT 1 FROM audit_log WHERE action = 'design.reset'"));
    }

    public function testCacheIsRebuiltWhenMissingOrOutdatedAndFallbackRouteWorks(): void
    {
        $url = $this->app->design()->stylesheetUrl();
        $file = $this->app->designDir() . '/' . basename($url);
        @unlink($file);
        $this->app->settings->set('design_cache', ['file' => basename($url), 'fp' => 'veraltet', 'hash' => 'x']);
        $app = new App(self::root(), self::config(), self::db());
        $app->useDesignDir($this->app->designDir());
        self::assertSame($url, $app->design()->stylesheetUrl());
        self::assertFileExists($file);
        self::assertFileExists($this->app->designDir() . '/aktuell.css');

        $r = $this->get('/designsystem.css?v=1');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('text/css', $r->headers['Content-Type']);
        self::assertStringContainsString('--color-primary:', $r->body);
        self::assertStringContainsString('immutable', $r->headers['Cache-Control']);
        $etag = $r->headers['ETag'];
        $r304 = $this->app->handle(new Request('GET', '/designsystem.css', [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_IF_NONE_MATCH' => $etag]));
        self::assertSame(304, $r304->status);
    }

    public function testCustomFontUploadUseAndDelete(): void
    {
        $this->loginAs('admin');
        $tmp = tempnam(sys_get_temp_dir(), 'font');
        copy(self::root() . '/public/assets/fonts/inter-latin-wght-normal.woff2', $tmp);
        $upload = fn(string $path, string $family) => $this->app->handle(new Request('POST', '/verwaltung/designsystem/schriften/', [],
            ['_csrf' => \Mea\Core\Csrf::token(), 'family' => $family, 'weight_min' => '100', 'weight_max' => '900', 'style' => 'normal', 'fallback' => 'sans'],
            ['REMOTE_ADDR' => '203.0.113.7'], [], ['font' => ['name' => 'haus.woff2', 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK, 'type' => 'font/woff2']]));
        self::assertSame(303, $upload($tmp, 'Hausschrift')->status);
        $font = self::db()->one('SELECT * FROM design_fonts WHERE family = ?', ['Hausschrift']);
        self::assertNotNull($font);
        self::assertFileExists($this->app->fontDir() . '/' . $font['file']);

        // Kein Webfont (Inhalt zählt, nicht die Endung) und unsichere Namen werden abgewiesen
        $fake = tempnam(sys_get_temp_dir(), 'font');
        file_put_contents($fake, '<?php echo 1;');
        $upload($fake, 'Falsch');
        self::assertNull(self::db()->value("SELECT 1 FROM design_fonts WHERE family = 'Falsch'"));
        $upload($tmp, 'Böse"; } body {');
        self::assertSame(1, (int) self::db()->value('SELECT COUNT(*) FROM design_fonts'));

        $id = 'custom-' . $font['id'];
        self::assertSame(303, $this->adminPost('/verwaltung/designsystem/', self::form(['font_body' => $id]))->status);
        $css = (string) file_get_contents($this->app->designDir() . '/' . basename($this->app->design()->stylesheetUrl()));
        self::assertStringContainsString('@font-face { font-family: "Hausschrift"; font-style: normal; font-weight: 100 900; font-display: swap; src: url("/media/fonts/' . $font['file'] . '") format("woff2");', $css);
        self::assertStringContainsString('--font-family-body: "Hausschrift", system-ui', $css);

        // Verwendete Schrift lässt sich nicht löschen
        $this->adminPost('/verwaltung/designsystem/schriften/' . $font['id'] . '/loeschen/', []);
        self::assertNotNull(self::db()->value('SELECT 1 FROM design_fonts WHERE id = ?', [$font['id']]));
        $this->adminPost('/verwaltung/designsystem/', self::form());
        $this->adminPost('/verwaltung/designsystem/schriften/' . $font['id'] . '/loeschen/', []);
        self::assertNull(self::db()->value('SELECT 1 FROM design_fonts WHERE id = ?', [$font['id']]));
        self::assertFileDoesNotExist($this->app->fontDir() . '/' . $font['file']);
        @unlink($tmp);
        @unlink($fake);
    }
}
