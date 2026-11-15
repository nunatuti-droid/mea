<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Module\Content\ContentRepository;
use Mea\Module\Content\HeroSlogans;
use Mea\Module\Content\SectionVisuals;

/**
 * Wandelt eine Leistungsseite (templates/public/service.twig mit facts_json) in einen Seitenbaukasten-Aufbau um,
 * der dieselben Abschnitte in derselben Reihenfolge ausgibt. Nur Aufbauten, die der Baukasten vollständig abbilden kann,
 * werden umgewandelt; sonst liefert convert() null und nennt den Grund.
 */
final class ServicePageConverter
{
    public function __construct(private ContentRepository $content) {}

    /** Passendes Symbol zu einem Text (gleiche Regeln wie das Makro pick in service.twig). */
    public static function pick(string $text, int $i): string
    {
        $k = mb_strtolower($text);
        $has = fn(array $words): bool => array_filter($words, fn($w) => str_contains($k, $w)) !== [];
        return match (true) {
            $has(['sprach', 'hören', 'sprechen', 'lesen']) => 'languages',
            $has(['förder', 'avgs', 'gutschein', 'kosten', 'finanz', 'azav']) => 'file-text',
            $has(['zulassung', 'prüf', 'zertifikat', 'nachweis', 'qualifikation']) => 'badge-check',
            $has(['ausbildung', 'lern', 'grundlagen', 'berufswunsch']) => 'graduation-cap',
            $has(['ort', 'region', 'ruhrgebiet', 'standort']) => 'map-pin',
            $has(['schritt', 'planen', 'ablauf', 'ziel']) => 'route',
            $has(['beratung', 'gespräch', 'persönlich', 'begleit']) => 'handshake',
            $has(['gruppe', 'einzel', 'team', 'unternehm', 'betrieb']) => 'users',
            default => ['target', 'users', 'graduation-cap', 'handshake', 'route', 'badge-check'][($i - 1) % 6],
        };
    }

    /**
     * @param array<string,mixed> $service Leistung mit entschlüsselten facts
     * @param array{phone?:string, phone_display?:string} $company
     * @return array{tree:list<array<string,mixed>>|null, slug:string, reason:string}
     */
    public function convert(array $service, array $company): array
    {
        $parent = !empty($service['parent_id']) ? $this->content->serviceById((int) $service['parent_id']) : null;
        $slug = ($parent ? $parent['slug'] . '/' : '') . $service['slug'];
        $no = fn(string $why): array => ['tree' => null, 'slug' => $slug, 'reason' => $why];
        $facts = (array) ($service['facts'] ?? []);

        if (isset($facts['facts'])) {
            return $no('Eckdaten („Auf einen Blick“) sind im Baukasten noch nicht abgebildet.');
        }
        if (trim((string) ($service['body_html'] ?? '')) !== '') {
            return $no('Die Seite hat einen freien Haupttext mit Eckdaten-Box, der im Baukasten noch nicht abgebildet ist.');
        }
        $children = $this->content->childServices((int) $service['id']);
        $topics = array_values(array_filter($children, fn($c) => !empty($c['facts']['info'])));
        $measures = array_values(array_filter($children, fn($c) => empty($c['facts']['info'])));
        if ($measures !== []) {
            return $no('Maßnahmenkarten mit Kennzahlen sind im Baukasten noch nicht abgebildet.');
        }

        $tree = [];
        $title = (string) $service['title'];
        $isChild = $parent !== null;
        $btn1 = (string) ($service['cta_url'] ?? '') !== '';
        $tel = preg_replace('/[^0-9+]/', '', (string) ($company['phone'] ?? '')) ?? '';
        $slogan = (string) ($facts['slogan'] ?? '');
        if ($slogan === '') {
            $slogan = HeroSlogans::get($slug) ?: HeroSlogans::get((string) $service['slug']);
        }
        $tree[] = $this->node('page_hero', [
            'eyebrow' => $isChild ? (string) (($parent['menu_title'] ?? '') ?: $parent['title']) : 'MEA Campus',
            'title' => $title, 'slogan' => $slogan, 'intro' => (string) (($service['intro'] ?? '') ?: ($service['teaser'] ?? '')),
            'media' => $service['hero_media_id'] ? (int) $service['hero_media_id'] : null, 'crumbs' => true,
            'crumb' => $isChild ? '' : (string) (($service['menu_title'] ?? '') ?: $title),
            'root_label' => 'Campus', 'root_url' => '/campus/',
            'parent_label' => $isChild ? (string) (($parent['menu_title'] ?? '') ?: $parent['title']) : '', 'parent_url' => $isChild ? '/' . $parent['slug'] . '/' : '',
            'btn1_label' => $btn1 ? (string) (($service['cta_label'] ?? '') ?: 'Beratung anfragen') : '', 'btn1_url' => $btn1 ? (string) $service['cta_url'] : '',
            'btn2_label' => $btn1 ? 'Anrufen: ' . ($company['phone_display'] ?? '') : '', 'btn2_url' => $btn1 ? 'tel:' . $tel : '',
        ]);

        // Abschnitte
        foreach (array_values((array) ($facts['sections'] ?? [])) as $i => $sec) {
            $n = $i + 1;
            $bg = $n % 2 === 0 ? 'alt' : 'none';
            $text = (string) ($sec['title'] ?? '') . ' ' . (string) ($sec['text'] ?? '');
            if (!empty($sec['cards'])) {
                $cards = array_values((array) $sec['cards']);
                $tree[] = $this->node('section', ['bg' => $bg], [
                    $this->head((string) ($sec['eyebrow'] ?? ''), (string) ($sec['title'] ?? ''), (string) ($sec['text'] ?? '')),
                    $this->node('cards', ['cols' => count($cards) > 2 ? '3' : '2', 'look' => 'symbol', 'level' => 'h3', 'items' => array_map(
                        fn($c, $k) => ['icon' => $this->icon(self::pick((string) ($c['title'] ?? '') . ' ' . (string) ($c['text'] ?? ''), $k + 1)), 'media' => null,
                            'title' => (string) ($c['title'] ?? ''), 'text' => (string) ($c['text'] ?? ''), 'link_label' => '', 'url' => ''], $cards, array_keys($cards))]),
                ]);
                continue;
            }
            $vis = SectionVisuals::get((string) ($sec['eyebrow'] ?? '')) ?? ['icon' => (string) ($sec['icon'] ?? self::pick($text, $n)), 'points' => (array) ($sec['points'] ?? [])];
            $points = (array) ($sec['points'] ?? $vis['points'] ?? []);
            $tree[] = $this->node('section_visual', [
                'eyebrow' => (string) ($sec['eyebrow'] ?? ''), 'title' => (string) ($sec['title'] ?? ''), 'text' => (string) ($sec['text'] ?? ''),
                'icon' => $this->icon((string) ($sec['icon'] ?? $vis['icon'])), 'bg' => $bg,
                'points' => array_map(fn($p) => ['icon' => $this->icon((string) ($p['icon'] ?? 'check')), 'title' => (string) ($p['title'] ?? ''), 'text' => (string) ($p['text'] ?? '')], array_values($points)),
            ]);
        }

        // Angebotsbausteine (Module)
        if (isset($facts['modules'])) {
            $tree[] = $this->node('section', ['bg' => 'none'], [
                $this->head('Unser Angebot', (string) ($facts['modules_title'] ?? 'Das gehört dazu'), ''),
                $this->node('cards', ['cols' => '3', 'look' => 'symbol', 'level' => 'h3', 'items' => array_map(
                    fn($m) => ['icon' => $this->icon((string) ($m['icon'] ?? 'sparkles') ?: 'sparkles'), 'media' => null, 'title' => (string) ($m['title'] ?? ''), 'text' => (string) ($m['text'] ?? ''), 'link_label' => '', 'url' => ''],
                    array_values((array) $facts['modules']))]),
            ]);
        }

        // Ablauf
        if (isset($facts['process'])) {
            $tree[] = $this->node('section', ['bg' => 'alt'], [
                $this->head((string) ($facts['process_eyebrow'] ?? 'Ablauf'), (string) ($facts['process_title'] ?? 'Unser Rekrutierungsprozess im Überblick'), ''),
                $this->node('steps', ['items' => array_map(fn($s) => ['title' => (string) ($s['title'] ?? ''), 'text' => (string) ($s['text'] ?? '')], array_values((array) $facts['process']))]),
            ]);
        }

        // Unterseiten des Bereichs
        if ($topics !== []) {
            $tree[] = $this->node('subpage_cards', ['eyebrow' => (string) ($facts['topics_eyebrow'] ?? 'Ihr Einstieg'), 'title' => (string) ($facts['topics_title'] ?? 'Wählen Sie das passende Thema'),
                'parent' => (string) $service['slug'], 'exclude' => '', 'kind' => 'info', 'min' => 1, 'bg' => 'none']);
        }
        if ($isChild) {
            $info = !empty($facts['info']);
            $tree[] = $this->node('subpage_cards', ['eyebrow' => '', 'title' => $info ? 'Weitere Themen' : 'Weitere Maßnahmen',
                'parent' => (string) $parent['slug'], 'exclude' => (string) $service['slug'], 'kind' => $info ? 'info' : 'measure', 'min' => 1, 'bg' => 'alt']);
        }

        // Häufige Fragen: Fragen aus der Verwaltung dieser Leistung und eigene Fragen der Seite
        $faq = [];
        foreach ($this->content->faqsFor('service', (int) $service['id']) as $f) {
            $faq[] = ['title' => (string) $f['question'], 'html' => (string) $f['answer_html']];
        }
        foreach ((array) ($facts['faq_items'] ?? []) as $f) {
            $faq[] = ['title' => (string) ($f['q'] ?? ''), 'html' => '<p>' . htmlspecialchars((string) ($f['a'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'];
        }
        if ($faq !== []) {
            $tree[] = $this->node('section', ['bg' => 'none'], [
                $this->head('Gut zu wissen', 'Häufige Fragen', ''),
                $this->node('accordion', ['items' => $faq, 'open' => false, 'schema' => true]),
            ]);
        }

        // Weitere Leistungen (nur Hauptleistungen)
        if (!$isChild) {
            $tree[] = $this->node('section', ['bg' => 'alt'], [
                $this->head('Mehr von MEA', 'Weitere Leistungen', ''),
                $this->node('services_grid', ['limit' => 3, 'more' => 'Mehr erfahren', 'exclude' => (string) $service['slug']]),
            ]);
        }

        // Abschluss-Band
        $tree[] = $this->node('section', ['bg' => 'none'], [
            $this->node('cta', ['title' => (string) ($facts['cta_title'] ?? 'Lassen Sie sich persönlich beraten'),
                'text' => (string) ($facts['cta_text'] ?? 'Wir nehmen uns Zeit für Ihre Fragen – am Telefon, per WhatsApp oder vor Ort.'),
                'btn_label' => 'Nachricht schreiben', 'btn_url' => '/kontakt/', 'phone' => true]),
        ]);

        return ['tree' => $tree, 'slug' => $slug, 'reason' => ''];
    }

    private function icon(string $name): string
    {
        return in_array($name, BlockTypes::ICONS, true) ? $name : 'check';
    }

    /** @return array<string,mixed> */
    private function head(string $eyebrow, string $title, string $text): array
    {
        return $this->node('section_head', ['eyebrow' => $eyebrow, 'title' => $title, 'text' => $text, 'link_label' => '', 'link_url' => '', 'layout' => 'stack', 'level' => 'h2']);
    }

    /** @param array<string,mixed> $f @param list<array<string,mixed>>|null $c @return array<string,mixed> */
    private function node(string $type, array $f, ?array $c = null): array
    {
        $n = ['id' => TreeValidator::newId(), 'type' => $type, 'f' => $f + BlockTypes::defaults($type)];
        if (BlockTypes::acceptsChildren($type)) {
            $n['c'] = $c ?? [];
        }
        return $n;
    }
}
