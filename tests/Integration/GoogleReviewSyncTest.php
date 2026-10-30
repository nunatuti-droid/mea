<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Content\GoogleReviewSync;

/** Google-Bewertungen: Abruf über die Places API (mit Test-Antworten), Verwaltung und Anzeige. */
final class GoogleReviewSyncTest extends IntegrationTestCase
{
    /** @var list<array{0:string,1:string}> */
    private array $calls = [];

    private function fakeGoogle(array $reviews, int $status = 200): void
    {
        $this->app->useGoogleHttp(function (string $method, string $url, array $headers, ?array $json) use ($reviews, $status): array {
            $this->calls[] = [$method, $url];
            self::assertSame('test-key', $headers['X-Goog-Api-Key']);
            if (str_ends_with($url, 'places:searchText')) {
                return ['status' => 200, 'body' => ['places' => [
                    ['id' => 'ChIJfalsch', 'googleMapsUri' => 'https://maps.google.com/?cid=1'],
                    ['id' => 'ChIJrichtig123', 'googleMapsUri' => 'https://maps.google.com/?cid=7580062879415361135'],
                ]]];
            }
            if ($status !== 200) {
                return ['status' => $status, 'body' => ['error' => ['message' => 'API key not valid.']]];
            }
            self::assertStringContainsString('places/ChIJrichtig123', $url);
            return ['status' => 200, 'body' => ['rating' => 4.87, 'userRatingCount' => 52, 'reviews' => $reviews]];
        });
    }

    private static function review(string $id, string $name, int $stars, string $text): array
    {
        return ['name' => 'places/ChIJrichtig123/reviews/' . $id, 'rating' => $stars, 'text' => ['text' => $text, 'languageCode' => 'de'],
            'publishTime' => '2026-09-14T10:00:00Z', 'authorAttribution' => ['displayName' => $name, 'uri' => 'https://www.google.com/maps/contrib/1']];
    }

    public function testCidFromMapsLink(): void
    {
        $url = 'https://www.google.com/maps/place/MEA+Bildungszentrum+GmbH/@51.6483196,7.0867044,17z/data=!3m1!4b1!4m6!3m5!1s0x47b8fb549157b9c9:0x6931cb0726ad5e6f!8m2';
        self::assertSame('7580062879415361135', GoogleReviewSync::cidFromUrl($url));
        self::assertSame('123', GoogleReviewSync::cidFromUrl('https://www.google.com/maps?cid=123'));
        self::assertNull(GoogleReviewSync::cidFromUrl('https://g.page/r/abc'));
    }

    public function testAdminSavesKeyAndSyncFillsRatingAndReviews(): void
    {
        $this->loginAs('admin');
        $res = $this->adminPost('/verwaltung/google-bewertungen/verbindung/', [
            'profile_url' => 'https://www.google.com/maps?cid=7580062879415361135', 'api_key' => 'test-key', 'place_id' => '', 'rating' => '', 'count' => '', 'as_of' => '',
        ]);
        self::assertSame(303, $res->status);
        $raw = (string) self::db()->value("SELECT value_json FROM settings WHERE setting_key = 'google_api'");
        self::assertStringNotContainsString('test-key', $raw, 'Schlüssel nur verschlüsselt');

        $this->fakeGoogle([self::review('a', 'Anna K.', 5, 'Sehr gute Prüfungsvorbereitung.'), self::review('b', 'Ben M.', 4, 'Freundliches Team.')]);
        self::assertSame(303, $this->adminPost('/verwaltung/google-bewertungen/abrufen/', [])->status);
        self::assertSame(2, (int) self::db()->value("SELECT COUNT(*) FROM google_reviews WHERE source = 'google'"));
        $g = $this->app->settings->get('google');
        self::assertSame('4,9', $g['rating']);
        self::assertSame(52, $g['count']);
        self::assertSame('ChIJrichtig123', $this->app->settings->get('google_api')['place_id']);

        // Ausgeblendete Bewertung bleibt beim nächsten Abruf ausgeblendet; nicht mehr gelieferte wird entfernt
        $id = (int) self::db()->value("SELECT id FROM google_reviews WHERE author_name = 'Anna K.'");
        $this->adminPost('/verwaltung/google-bewertungen/' . $id . '/aktiv/', ['value' => '0']);
        $this->fakeGoogle([self::review('a', 'Anna K.', 5, 'Sehr gute Prüfungsvorbereitung!')]);
        $this->app->googleReviews()->sync(new \DateTimeImmutable('2026-11-01 03:00', new \DateTimeZone('UTC')));
        self::assertSame(0, (int) self::db()->value('SELECT is_active FROM google_reviews WHERE id = ?', [$id]));
        self::assertSame('Sehr gute Prüfungsvorbereitung!', self::db()->value('SELECT body FROM google_reviews WHERE id = ?', [$id]));
        self::assertNull(self::db()->value("SELECT id FROM google_reviews WHERE author_name = 'Ben M.'"));

        $list = $this->get('/verwaltung/google-bewertungen/');
        self::assertSame(200, $list->status);
        self::assertStringContainsString('Anna K.', $list->body);
        self::assertStringNotContainsString('test-key', $list->body);
    }

    public function testFailedSyncIsShownAndKeepsOldValues(): void
    {
        $this->app->settings->set('google', ['profile_url' => 'https://www.google.com/maps?cid=7580062879415361135', 'rating' => '4,5', 'count' => 10]);
        $this->app->googleReviews()->saveConnection('test-key', 'ChIJrichtig123');
        $this->fakeGoogle([], 400);
        try {
            $this->app->googleReviews()->sync(new \DateTimeImmutable());
            self::fail('Fehler erwartet');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('API key not valid', $e->getMessage());
        }
        self::assertSame('4,5', $this->app->settings->get('google')['rating']);
        $this->loginAs('admin');
        self::assertStringContainsString('Letzter Abruf fehlgeschlagen', $this->get('/verwaltung/google-bewertungen/')->body);
    }

    public function testFooterShowsVisibleGoogleReviewsAndHeroHasNoQuoteCard(): void
    {
        $this->app->settings->set('google', ['profile_url' => 'https://www.google.com/maps?cid=1', 'rating' => '4,9', 'count' => 52, 'as_of' => '01.11.2026']);
        self::db()->run("INSERT INTO google_reviews (source, author_name, rating, body, published_at, is_active) VALUES
            ('manual', 'Clara S.', 5, 'Top Beratung in Marl.', '2026-10-01', 1), ('manual', 'Versteckt V.', 1, 'Nicht zeigen.', '2026-10-02', 0)");
        $this->app = new \Mea\Core\App(self::root(), self::config(), self::db());
        $body = $this->get('/')->body;
        self::assertStringContainsString('class="g-hero"', $body);
        self::assertStringContainsString('52 Google-Bewertungen', $body);
        self::assertStringContainsString('Top Beratung in Marl.', $body);
        self::assertStringNotContainsString('Nicht zeigen.', $body);
        self::assertStringNotContainsString('hero-quote', $body);
    }

    public function testManualGoogleReviewCanBeCreated(): void
    {
        $this->loginAs('admin');
        $res = $this->adminPost('/verwaltung/google-bewertungen/neu/', ['author_name' => 'Dana L.', 'rating' => '5', 'body' => 'Klasse!', 'published_at' => '2026-10-20', 'is_active' => '1', 'sort_order' => '0']);
        self::assertSame(303, $res->status, $res->body);
        self::assertSame('manual', self::db()->value("SELECT source FROM google_reviews WHERE author_name = 'Dana L.'"));
    }
}
