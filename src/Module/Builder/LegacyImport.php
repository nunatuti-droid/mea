<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Core\Database;

/**
 * Übernimmt die bisherigen Seiten in den Seitenbaukasten. Wiederholbar: bearbeitet nur Seiten ohne Aufbau
 * und legt die Startseite nur an, wenn es noch keine gibt. Texte, die bisher fest in den Templates standen,
 * werden zu Feldern der Bausteine; Bilder, Adressen und SEO-Angaben bleiben erhalten.
 */
final class LegacyImport
{
    public const HOME_SLUG = 'startseite';

    public function __construct(private Database $db) {}

    /** @return list<string> Protokoll */
    public function run(): array
    {
        $log = [];
        if ((int) $this->db->value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', ['company']) === 0
            && (int) $this->db->value('SELECT COUNT(*) FROM pages') === 0) {
            return ['Noch keine Inhalte vorhanden – Übernahme folgt nach den Startdaten.'];
        }
        $this->db->transaction(function () use (&$log): void {
            if ($this->db->value('SELECT id FROM pages WHERE is_home = 1') === null) {
                $seo = $this->setting('seo_home');
                $existing = $this->db->value('SELECT id FROM pages WHERE slug = ?', [self::HOME_SLUG]);
                if ($existing !== null) {
                    $this->db->run('UPDATE pages SET is_home = 1, is_system = 1, status = ? WHERE id = ?', ['published', $existing]);
                } else {
                    $this->db->insert('pages', [
                        'slug' => self::HOME_SLUG, 'title' => 'Startseite', 'template' => 'default', 'status' => 'published', 'is_system' => 1, 'is_home' => 1,
                        'seo_title' => mb_substr((string) ($seo['title'] ?? ''), 0, 70) ?: null,
                        'seo_description' => mb_substr((string) ($seo['description'] ?? ''), 0, 170) ?: null,
                        'published_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                }
                $log[] = 'Startseite in der Seitenverwaltung angelegt.';
            }
            foreach ($this->db->all('SELECT * FROM pages WHERE blocks_json IS NULL') as $p) {
                $tree = (int) ($p['is_home'] ?? 0) === 1 ? $this->home() : $this->page($p);
                $this->db->run('UPDATE pages SET blocks_json = ? WHERE id = ?', [json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $p['id']]);
                $log[] = 'Seite übernommen: ' . $p['title'];
            }
        });
        return $log;
    }

    /** @return array<string,mixed> */
    private function setting(string $key): array
    {
        $v = $this->db->value('SELECT value_json FROM settings WHERE setting_key = ?', [$key]);
        return $v === null ? [] : (array) json_decode((string) $v, true);
    }

    /** @param array<string,mixed> $f @param list<array<string,mixed>>|null $c @return array<string,mixed> */
    private static function n(string $type, array $f = [], ?array $c = null, array $s = []): array
    {
        $n = ['id' => TreeValidator::newId(), 'type' => $type, 'f' => $f + BlockTypes::defaults($type)];
        if ($s !== []) {
            $n['s'] = $s;
        }
        if (BlockTypes::acceptsChildren($type)) {
            $n['c'] = $c ?? [];
        }
        return $n;
    }

    /** @param list<array<string,mixed>> $c @param array<string,mixed> $f */
    private static function section(array $c, string $bg = 'none', array $f = []): array
    {
        return self::n('section', ['bg' => $bg] + $f, $c);
    }

    private static function head(string $eyebrow, string $title, string $text = '', string $link = '', string $url = '', string $layout = 'stack'): array
    {
        return self::n('section_head', ['eyebrow' => $eyebrow, 'title' => $title, 'text' => $text, 'link_label' => $link, 'link_url' => $url, 'layout' => $link !== '' ? 'split' : $layout]);
    }

    private static function cta(string $title, string $text): array
    {
        return self::n('cta', ['title' => $title, 'text' => $text, 'btn_label' => 'Nachricht schreiben', 'btn_url' => '/kontakt/', 'phone' => true]);
    }

    /** @return list<array<string,mixed>> */
    private function steps(): array
    {
        $steps = $this->setting('booking')['steps'] ?? [];
        return array_map(fn($s) => ['title' => mb_substr((string) ($s['title'] ?? ''), 0, 80), 'text' => mb_substr((string) ($s['text'] ?? ''), 0, 300)], is_array($steps) ? $steps : []);
    }

    private function examCity(): string
    {
        return (string) ($this->db->value('SELECT city FROM locations WHERE is_exam_center = 1 AND is_active = 1 ORDER BY id LIMIT 1') ?? '');
    }

    private static function media(mixed $id): ?int
    {
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /** Startseite aus den bisherigen Einstellungen (home) und dem Template public/home.twig. @return list<array<string,mixed>> */
    private function home(): array
    {
        $h = $this->setting('home');
        $city = $this->examCity();
        $tree = [];
        $tree[] = self::n('hero_home', [
            'media' => self::media($h['hero_media_id'] ?? null), 'badge' => (string) ($h['eyebrow'] ?? ''), 'title' => (string) ($h['title'] ?? 'MEA Bildungszentrum'),
            'lead' => (string) ($h['lead'] ?? ''), 'btn1_label' => 'Unsere Leistungen', 'btn1_url' => '/leistungen/', 'btn2_label' => 'Prüfungstermine', 'btn2_url' => '/telc-pruefungszentrum/',
            'rating' => true,
            'benefits' => array_map(fn($b) => ['icon' => in_array($b['icon'] ?? '', BlockTypes::ICONS, true) ? $b['icon'] : 'check', 'title' => (string) ($b['title'] ?? '')], array_slice((array) ($h['benefits'] ?? []), 0, 4)),
        ]);
        $partners = array_values(array_filter(array_map(fn($p) => ['media' => self::media($p['media_id'] ?? null), 'label' => (string) ($p['label'] ?? '')], (array) ($h['partners'] ?? [])), fn($p) => $p['media'] !== null));
        if ($partners !== []) {
            $tree[] = self::n('partners', ['label' => 'Zulassungen und Partner', 'items' => $partners]);
        }
        $tree[] = self::section([
            self::head('Unsere Leistungen', 'Bildung, Integration und Fachkräftegewinnung', 'Für Menschen, die beruflich weiterkommen wollen, und für Unternehmen, die Fachkräfte suchen. Wählen Sie einen Bereich und erfahren Sie, wie wir Sie unterstützen.', 'Alle Leistungen', '/leistungen/'),
            self::n('services_grid'),
        ]);
        $tree[] = self::section([
            self::n('columns', ['layout' => '1-1', 'valign' => 'start', 'gap' => 'large'], s: ['t' => ['gap' => 32]], c: [
                self::n('column', [], [self::n('image', ['media' => self::media($h['about_media_id'] ?? null), 'ratio' => '5-4', 'shape' => 'signature'])]),
                self::n('column', [], [
                    self::n('eyebrow', ['text' => 'Warum MEA']),
                    self::n('heading', ['text' => (string) ($h['about_title'] ?? 'Warum MEA'), 'level' => 'h2']),
                    self::n('text', ['html' => '<p>' . htmlspecialchars((string) ($h['about_text'] ?? ''), ENT_QUOTES) . '</p>', 'look' => 'lead']),
                ]),
            ]),
            self::n('cards', ['cols' => '4', 'look' => 'card', 'level' => 'h3', 'items' => array_map(fn($w) => [
                'icon' => in_array($w['icon'] ?? '', BlockTypes::ICONS, true) ? $w['icon'] : 'check', 'media' => null, 'title' => (string) ($w['title'] ?? ''),
                'text' => (string) ($w['text'] ?? ''), 'link_label' => '', 'url' => '',
            ], array_slice((array) ($h['why'] ?? []), 0, 24))]),
        ], 'alt');
        $tree[] = self::section([
            self::head('telc-Prüfungszentrum' . ($city !== '' ? ' ' . $city : ''), 'Sprachprüfungen mit anerkanntem Zertifikat',
                'Wählen Sie Ihre Prüfung, sehen Sie Termine, Preis und freie Plätze und melden Sie sich direkt an.' . ($city !== '' ? ' Alle Prüfungen finden persönlich in unserem zertifizierten Prüfungszentrum in ' . $city . ' statt.' : ''),
                'Alle Prüfungen und Termine', '/telc-pruefungszentrum/'),
            self::n('exam_cards', ['limit' => 6]),
        ]);
        $tree[] = self::section([self::head('Aktuelle Termine', 'Die nächsten freien Prüfungstermine'), self::n('next_sessions', ['limit' => 6, 'hide_empty' => true])], 'alt');
        $tree[] = self::section([self::head("So einfach geht's", 'In vier Schritten zur Prüfung'), self::n('steps', ['items' => $this->steps()])]);
        $tree[] = self::section([self::head('Stimmen', 'Was unsere Teilnehmenden sagen'), self::n('testimonials', ['source' => 'db', 'limit' => 24, 'hide_empty' => true])], 'alt', ['anchor' => 'stimmen']);
        $tree[] = self::section([
            self::n('columns', ['layout' => '1-1', 'valign' => 'center', 'gap' => 'large'], s: ['t' => ['gap' => 32]], c: [
                self::n('column', [], [
                    self::n('eyebrow', ['text' => 'Häufige Fragen']),
                    self::n('heading', ['text' => 'Gut zu wissen vor der Anmeldung', 'level' => 'h2']),
                    self::n('text', ['html' => '<p>Ihre Frage ist nicht dabei? Wir helfen gerne persönlich weiter.</p>', 'look' => 'lead']),
                    self::n('button', ['label' => 'Alle Fragen ansehen', 'url' => '/faq/', 'look' => 'arrow', 'icon' => '']),
                ]),
                self::n('column', [], [self::n('faq', ['source' => 'home', 'schema' => true, 'hide_empty' => true])]),
            ]),
        ]);
        $tree[] = self::section([self::cta('Fragen zu Prüfung, Kurs oder Coaching?', 'Wir beraten Sie persönlich und unverbindlich – telefonisch, per WhatsApp oder an einem unserer Standorte in Marl und Herne. Gemeinsam finden wir den passenden nächsten Schritt.')], 'alt', ['aria' => 'Kontakt']);
        return $tree;
    }

    /** Bisherige Seite nach Adresse bzw. Vorlage. @param array<string,mixed> $p @return list<array<string,mixed>> */
    private function page(array $p): array
    {
        $intro = (string) ($p['intro'] ?? '');
        $media = self::media($p['hero_media_id'] ?? null);
        $body = (string) ($p['body_html'] ?? '');
        $hero = fn(array $f = []) => self::n('page_hero', $f + ['intro' => $intro, 'media' => $media]);
        switch ($p['slug']) {
            case 'leistungen':
                return [
                    $hero(['eyebrow' => 'Was wir für Sie tun', 'crumb' => 'Leistungen']),
                    self::section([self::n('heading', ['text' => 'Alle Leistungen', 'level' => 'h2', 'look' => 'sr']), self::n('services_grid')]),
                    self::section([self::cta('Welche Leistung passt zu Ihnen?', 'Wir beraten Sie unverbindlich, klären Ihre Fragen zu Förderung und Ablauf und finden gemeinsam den richtigen Weg.')], 'alt'),
                ];
            case 'faq':
                return [
                    $hero(['media' => null, 'crumb' => 'Häufige Fragen']),
                    self::section([self::n('faq', ['source' => 'faq_page', 'grouped' => true, 'schema' => true])]),
                    self::section([self::cta('Ihre Frage war nicht dabei?', 'Schreiben Sie uns oder rufen Sie an – wir beantworten Ihre Frage persönlich.')], 'alt'),
                ];
            case 'preise':
                return [$hero(['media' => null, 'parent_label' => 'Prüfungen', 'parent_url' => '/telc-pruefungszentrum/']), self::section([self::n('price_table')])];
            case 'standorte':
                return [$hero(['media' => null, 'crumb' => 'Standorte']), self::section([self::n('locations', ['look' => 'full', 'cols' => '2'])])];
            case 'kontakt':
                return [
                    $hero(['media' => null, 'crumb' => 'Kontakt']),
                    self::section([self::n('columns', ['layout' => '4-3', 'valign' => 'start', 'gap' => 'large'], [
                        self::n('column', [], [self::n('contact_form')]),
                        self::n('column', [], [self::n('contact_info', ['title' => 'Kontakt', 'locations' => true, 'look' => 'brand'])]),
                    ])]),
                ];
            case 'telc-pruefungszentrum':
                $tree = [
                    $hero(['eyebrow' => 'Zertifiziert. Anerkannt. Zukunftsorientiert.', 'crumb' => 'Prüfungen', 'btn1_label' => 'Prüfung auswählen', 'btn1_url' => '#pruefungen', 'btn2_label' => 'Preise ansehen', 'btn2_url' => '/preise/']),
                    self::section([
                        self::head('', 'Unsere Prüfungen', 'Wählen Sie die passende Prüfung. Auf der jeweiligen Seite finden Sie Ablauf, Voraussetzungen, freie Termine und den Preis und können sich direkt online anmelden.'),
                        self::n('exam_list'),
                    ], 'none', ['anchor' => 'pruefungen']),
                    self::section([self::head('Anmeldung', 'So melden Sie sich an'), self::n('steps', ['items' => $this->steps()])], 'alt'),
                ];
                if (trim(strip_tags($body)) !== '') {
                    $tree[] = self::section([self::n('image_text', ['media' => $media, 'title' => '', 'html' => $body, 'side' => 'right', 'valign' => 'center', 'ratio' => '5-4'])]);
                }
                $tree[] = self::section([self::head('Häufige Fragen', 'Rund um die Prüfung'), self::n('faq', ['source' => 'faq_page', 'schema' => true, 'hide_empty' => true])], 'alt');
                $tree[] = self::section([self::cta('Unsicher, welche Prüfung passt?', 'Wir beraten Sie gerne zur passenden Prüfung und zu unseren Vorbereitungskursen – telefonisch, per WhatsApp oder persönlich.')]);
                return $tree;
        }
        return match ($p['template']) {
            'legal' => [$hero(['media' => null]), self::section([self::n('text', ['html' => $body])])],
            'about' => [
                $hero(['eyebrow' => 'Unser Leitbild']),
                self::section([self::n('text', ['html' => $body])]),
                self::section([self::head('Stimmen', 'Was unsere Teilnehmenden sagen'), self::n('google_rating'), self::n('testimonials', ['source' => 'db', 'limit' => 24, 'hide_empty' => true])], 'alt', ['anchor' => 'stimmen']),
                self::section([self::head('Vor Ort', 'Unsere Standorte'), self::n('locations', ['look' => 'compact', 'cols' => '3'])]),
                self::section([self::cta('Lernen Sie uns kennen', 'Wir freuen uns auf Ihre Nachricht oder Ihren Anruf und beraten Sie gerne persönlich an einem unserer Standorte.')], 'alt'),
            ],
            default => array_values(array_filter([
                $hero(),
                self::section(array_values(array_filter([
                    self::n('text', ['html' => $body]),
                    $p['slug'] === 'karriere' ? self::n('button', ['label' => 'Jetzt online bewerben', 'url' => '/karriere/bewerbung/', 'icon' => 'send']) : null,
                ]))),
                self::section([self::cta('Fragen? Wir sind für Sie da.', 'Schreiben Sie uns, rufen Sie an oder nutzen Sie WhatsApp – wir melden uns schnellstmöglich zurück.')], 'alt'),
            ])),
        };
    }

    /** Aufbau für eine neue, leere Seite. @return list<array<string,mixed>> */
    public static function blank(): array
    {
        return [self::n('page_hero', ['intro' => '']), self::section([self::n('text', ['html' => '<p>Ihr Text.</p>'])])];
    }
}
