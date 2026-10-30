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
}
