<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Google-Bewertungen: nur Link und selbst eingetragene Werte, nichts ohne Profil-Link. */
final class GoogleRatingTest extends IntegrationTestCase
{
    public function testHiddenWithoutProfileLink(): void
    {
        self::assertStringNotContainsString('Bewertungen auf Google ansehen', $this->get('/ueber-uns/')->body);
    }

    public function testShowsLinkAndRatingFromSettings(): void
    {
        $this->app->settings->set('google', ['profile_url' => 'https://g.page/r/beispiel', 'rating' => '4,8', 'count' => 37, 'as_of' => '10/2026']);
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        $body = $this->get('/ueber-uns/')->body;
        self::assertStringContainsString('href="https://g.page/r/beispiel"', $body);
        self::assertStringContainsString('4,8 von 5', $body);
        self::assertStringContainsString('37 Google-Bewertungen', $body);
        self::assertStringNotContainsString('maps.googleapis', $body, 'kein Abruf bei Google');
    }

    public function testHeroAndFooterShowGoogleModule(): void
    {
        $this->app->settings->set('google', ['profile_url' => 'https://g.page/r/beispiel', 'rating' => '4,8', 'count' => 37, 'as_of' => '10/2026']);
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        $body = $this->get('/')->body;
        self::assertStringContainsString('class="g-hero"', $body);
        self::assertStringContainsString('class="g-card"', $body);
        self::assertStringContainsString('37 Google-Bewertungen', $body);
    }

    public function testReviewCanBeHiddenWithOneClick(): void
    {
        $this->loginAs('admin');
        $id = (int) self::db()->value('SELECT id FROM reviews WHERE is_active = 1 ORDER BY id LIMIT 1');
        $name = (string) self::db()->value('SELECT body FROM reviews WHERE id = ?', [$id]);
        $res = $this->adminPost('/verwaltung/bewertungen/' . $id . '/aktiv/', ['value' => '0']);
        self::assertSame(303, $res->status);
        self::assertSame(0, (int) self::db()->value('SELECT is_active FROM reviews WHERE id = ?', [$id]));
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        self::assertStringNotContainsString(htmlspecialchars(mb_substr($name, 0, 30)), $this->get('/ueber-uns/')->body);
        $this->adminPost('/verwaltung/bewertungen/' . $id . '/aktiv/', ['value' => '1']);
        self::assertSame(1, (int) self::db()->value('SELECT is_active FROM reviews WHERE id = ?', [$id]));
    }
}
