<?php
declare(strict_types=1);

namespace Mea\Module\Consent;

use Mea\Module\Content\SettingsRepository;

/**
 * Cookie-Einwilligung: welche Dienste eingebunden sind und zu welcher Kategorie sie gehören.
 * Dienste ohne eingetragene Kennung (z. B. leere Google-Analytics-ID) werden nie geladen und nicht angezeigt.
 * Ändert sich die Liste der Dienste, ändert sich die Version und Besucher werden erneut gefragt.
 */
final class ConsentConfig
{
    public const KEY = 'consent';
    public const CATEGORIES = ['necessary', 'statistics', 'marketing', 'media'];

    public function __construct(private SettingsRepository $settings) {}

    /** @return array<string,mixed> */
    private function raw(): array
    {
        $v = $this->settings->get(self::KEY, []);
        return is_array($v) ? $v : [];
    }

    public function gaId(): ?string
    {
        $id = strtoupper(trim((string) ($this->raw()['ga_id'] ?? '')));
        return preg_match('/^G-[A-Z0-9]{4,15}$/', $id) ? $id : null;
    }

    public function metaPixelId(): ?string
    {
        $id = trim((string) ($this->raw()['meta_pixel_id'] ?? ''));
        return preg_match('/^\d{8,20}$/', $id) ? $id : null;
    }

    public function youtube(): bool
    {
        return (bool) ($this->raw()['youtube'] ?? false);
    }

    /** @return list<array{key:string,label:string,text:string,required:bool,services:list<array{name:string,text:string}>}> */
    public function categories(): array
    {
        $cats = [[
            'key' => 'necessary', 'label' => 'Notwendig', 'required' => true,
            'text' => 'Für den Betrieb der Website erforderlich, z. B. für die Prüfungsanmeldung, den Schutz von Formularen und das Speichern Ihrer Cookie-Auswahl. Diese Cookies können nicht abgewählt werden.',
            'services' => [['name' => 'MEA-Website', 'text' => 'Sitzungs-Cookie (Anmeldung, Formularschutz) und Cookie „mea_consent“ mit Ihrer Auswahl (12 Monate).']],
        ]];
        if ($this->gaId() !== null) {
            $cats[] = ['key' => 'statistics', 'label' => 'Statistik', 'required' => false,
                'text' => 'Hilft uns zu verstehen, wie die Website genutzt wird, damit wir sie verbessern können.',
                'services' => [['name' => 'Google Analytics', 'text' => 'Anbieter: Google Ireland Limited. Daten können in die USA übertragen werden.']]];
        }
        if ($this->metaPixelId() !== null) {
            $cats[] = ['key' => 'marketing', 'label' => 'Marketing', 'required' => false,
                'text' => 'Ermöglicht es, die Wirkung unserer Anzeigen zu messen und Ihnen passende Inhalte zu zeigen.',
                'services' => [['name' => 'Meta-Pixel (Facebook/Instagram)', 'text' => 'Anbieter: Meta Platforms Ireland Limited. Daten können in die USA übertragen werden.']]];
        }
        $media = [['name' => 'Google Maps', 'text' => 'Karten auf den Standortseiten. Anbieter: Google Ireland Limited. Beim Laden werden Ihre IP-Adresse und weitere Daten an Google übertragen, auch in die USA.']];
        if ($this->youtube()) {
            $media[] = ['name' => 'YouTube', 'text' => 'Eingebettete Videos. Anbieter: Google Ireland Limited.'];
        }
        $cats[] = ['key' => 'media', 'label' => 'Externe Medien', 'required' => false,
            'text' => 'Inhalte anderer Anbieter wie Karten oder Videos. Ohne Einwilligung werden sie erst geladen, wenn Sie einzeln darauf klicken.',
            'services' => $media];
        return $cats;
    }

    /** Version: ändert sich mit den eingebundenen Diensten und mit der manuellen Versionsnummer. */
    public function version(): string
    {
        $basis = implode('|', [(int) ($this->raw()['revision'] ?? 1), $this->gaId() ? 'ga' : '', $this->metaPixelId() ? 'meta' : '', $this->youtube() ? 'yt' : '']);
        return substr(hash('sha256', $basis), 0, 10);
    }

    /** Für das Skript im Browser. @return array<string,mixed> */
    public function clientConfig(): array
    {
        return [
            'version' => $this->version(),
            'categories' => array_map(fn($c) => $c['key'], $this->categories()),
            'ga' => $this->gaId(),
            'pixel' => $this->metaPixelId(),
        ];
    }

    /** Zusätzliche Quellen für die Content-Security-Policy, nur für eingetragene Dienste. @return array{script:list<string>,connect:list<string>,img:list<string>,frame:list<string>} */
    public function cspSources(): array
    {
        $s = ['script' => [], 'connect' => [], 'img' => [], 'frame' => []];
        if ($this->gaId() !== null) {
            $s['script'][] = 'https://www.googletagmanager.com';
            $s['connect'] = array_merge($s['connect'], ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://www.googletagmanager.com']);
            $s['img'] = array_merge($s['img'], ['https://*.google-analytics.com', 'https://www.googletagmanager.com']);
        }
        if ($this->metaPixelId() !== null) {
            $s['script'][] = 'https://connect.facebook.net';
            $s['connect'][] = 'https://www.facebook.com';
            $s['img'][] = 'https://www.facebook.com';
        }
        if ($this->youtube()) {
            $s['frame'][] = 'https://www.youtube-nocookie.com';
        }
        return $s;
    }
}
