<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

/** Datenschutzerklärung: passende Abschnitte, Sprungmarke für Karten, keine E-Mail-Adresse im Klartext. */
final class PrivacyPolicyTest extends IntegrationTestCase
{
    public function testCoversServicesInUse(): void
    {
        $res = $this->get('/datenschutz/');
        self::assertSame(200, $res->status);
        foreach (['id="karten"', 'Google Ireland Limited', 'PayPal (Europe)', 'mea_consent', 'telc gGmbH', 'Kavalleriestraße'] as $needle) {
            self::assertStringContainsString($needle, $res->body);
        }
        self::assertStringNotContainsString('OpenStreetMap', $res->body);
        self::assertDoesNotMatchRegularExpression('/[a-z0-9._-]+@meabildungszentrum\.de/i', $res->body);
    }

    public function testSanitizerKeepsHeadingAnchors(): void
    {
        $s = new \Mea\Core\HtmlSanitizer(sys_get_temp_dir() . '/mea-purifier-test');
        self::assertSame('<h2 id="karten">Karten</h2>', $s->clean('<h2 id="karten">Karten</h2>'));
        self::assertSame('<p>x</p>', $s->clean('<p id="y">x</p>'));
    }
}
