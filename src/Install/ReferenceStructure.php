<?php
declare(strict_types=1);

namespace Mea\Install;

use Mea\Core\Database;
use Mea\Module\Builder\BlockTypes;
use Mea\Module\Builder\TreeValidator;

/**
 * Update 0.9.13: Struktur und Navigation nach der Vorgabe „Neue Struktur“ (Referenz: Campus-Seite mit vier Bereichen).
 * Hauptmenü: Startseite, Campus (Megamenü mit vier Bereichen), Über uns, Standorte, Kontakt; „Prüfung buchen“ bleibt der Button im Kopf.
 * Alles andere steht im Fußbereich. Ändert Adressen (Coaching), legt die Unterseiten der Bereiche an, ersetzt die Hub-Texte
 * und baut Haupt- und Fußmenü neu auf. Wiederholbar: vorhandene Einträge werden nicht doppelt angelegt.
 * Texte sind aus den Inhalten der Referenzseiten neu formuliert.
 */
final class ReferenceStructure
{
    private const URLS = [
        '/coaching-beratung/' => '/coaching/',
    ];

    public function __construct(private Database $db) {}

    /** @return list<string> Protokoll */
    public function run(): array
    {
        $log = [];
        $this->db->transaction(function () use (&$log): void {
            $log[] = $this->hubs();
            $log[] = $this->content();
            $log[] = $this->images();
            $log[] = $this->aboutPage();
            $log[] = $this->contactPage();
            $log[] = $this->fundingPage();
            $log[] = $this->menus();
            $log[] = (new LinkRewriter($this->db, self::URLS))->run();
        });
        return $log;
    }

    // ---------------------------------------------------------------- Bereiche und Unterseiten

    /** Coaching: neue Adresse /coaching/ (vorher /coaching-beratung/); Onboarding gehört zu den Informationsthemen. */
    private function hubs(): string
    {
        if ($this->db->value("SELECT 1 FROM services WHERE slug = 'coaching-beratung' AND parent_id IS NULL") !== null
            && $this->db->value("SELECT 1 FROM services WHERE slug = 'coaching' AND parent_id IS NULL") === null) {
            $this->db->run("UPDATE services SET slug = 'coaching', menu_title = 'Coaching & Beratung' WHERE slug = 'coaching-beratung' AND parent_id IS NULL");
        }
        $onb = $this->db->one("SELECT s.id, s.facts_json FROM services s JOIN services p ON p.id = s.parent_id WHERE s.slug = 'onboarding' AND p.slug = 'recruiting'");
        if ($onb !== null) {
            $facts = is_string($onb['facts_json']) ? (json_decode($onb['facts_json'], true) ?: []) : [];
            $facts['info'] = true;
            $this->update('services', (int) $onb['id'], ['facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'sort_order' => 1]);
        }
        return 'Bereichsadressen angepasst.';
    }

    /** Eigenes Foto je Unterseite (Original-Dateiname in der Mediathek); innerhalb eines Bereichs wird kein Foto doppelt gezeigt. */
    private const IMAGES = [
        'bildungszentrum/ausbildung' => 'hero-gemeinsames-lernen.jpg',
        'bildungszentrum/sprachtraining' => 'MEA-Sprachkurse-und-telc-pruefungen.webp',
        'bildungszentrum/pruefungen' => 'telc-Pruefungen.webp',
        'recruiting/unternehmen' => 'MEA-Leistungen.png',
        'coaching/foerderung' => 'Onboarding.webp',
        'coaching/teilnehmende' => 'MEA-Dozentin.jpg',
        'coaching/jobcenter' => 'MEA-Sprachdozent.webp',
        'sprache-beruf/berufsdeutsch' => 'telc_Deustch_A2_B1-1024x1024.webp',
        'sprache-beruf/unternehmen' => 'Coaching_MEA.png',
    ];

    public function images(): string
    {
        $this->db->run("UPDATE services SET icon = 'building-2' WHERE icon = 'building'"); // Symbol „building“ gibt es nicht
        $n = 0;
        foreach (self::IMAGES as $path => $file) {
            [$parent, $slug] = explode('/', $path);
            $media = $this->db->value('SELECT id FROM media WHERE original_name = ? ORDER BY id LIMIT 1', [$file]);
            $row = $this->db->one('SELECT s.id FROM services s JOIN services p ON p.id = s.parent_id WHERE p.slug = ? AND s.slug = ?', [$parent, $slug]);
            if ($media === null || $row === null) {
                continue;
            }
            $this->update('services', (int) $row['id'], ['hero_media_id' => (int) $media, 'card_media_id' => (int) $media]);
            $n++;
        }
        return "$n Unterseiten mit eigenem Foto.";
    }

    /**
     * Legt fehlende Unterseiten an und schreibt die ausführlichen Texte (ReferenceContent) in Bereichs- und Unterseiten.
     * Wiederholbar; vorhandene Kennzahlen (Module, Maßnahmen) bleiben erhalten.
     */
    public function content(): string
    {
        $n = 0;
        $created = 0;
        $sort = [];
        foreach (ReferenceContent::all() as $d) {
            $parent = null;
            if ($d['parent'] !== null) {
                $parent = $this->db->one('SELECT id, hero_media_id, card_media_id FROM services WHERE slug = ? AND parent_id IS NULL', [$d['parent']]);
                if ($parent === null) {
                    continue;
                }
                $row = $this->db->one('SELECT id, facts_json FROM services WHERE parent_id = ? AND slug = ?', [$parent['id'], $d['slug']]);
            } else {
                $row = $this->db->one('SELECT id, facts_json FROM services WHERE parent_id IS NULL AND slug = ?', [$d['slug']]);
            }
            $facts = $row !== null && is_string($row['facts_json']) ? (json_decode($row['facts_json'], true) ?: []) : [];
            if ($row === null) {
                if ($parent === null || !empty($d['update_only'])) {
                    continue;
                }
                $sort[$d['parent']] = ($sort[$d['parent']] ?? 10) + 1;
                $facts['info'] = true;
                $id = $this->db->insert('services', [
                    'parent_id' => $parent['id'], 'slug' => $d['slug'], 'title' => $d['title'], 'menu_title' => $d['menu'], 'icon' => $d['icon'] ?? null,
                    'teaser' => $d['teaser'], 'intro' => $d['intro'], 'body_html' => '', 'facts_json' => '{}',
                    'hero_media_id' => $parent['hero_media_id'], 'card_media_id' => $parent['card_media_id'],
                    'status' => 'published', 'sort_order' => $sort[$d['parent']],
                ]);
                $row = ['id' => $id];
                $created++;
            }
            foreach (['highlights', 'sections', 'faq_items', 'cta_title', 'cta_text', 'modules_title'] as $k) {
                unset($facts[$k]);
            }
            if (!isset($d['modules_title'])) {
                unset($facts['modules']); // allgemeine Standardkarten ersetzen die neuen Abschnitte
            }
            $facts['highlights'] = $d['highlights'] ?? [];
            $facts['sections'] = $d['sections'] ?? [];
            $facts['faq_items'] = $d['faq'] ?? [];
            $facts['cta_title'] = $d['cta_title'] ?? '';
            $facts['cta_text'] = $d['cta_text'] ?? '';
            if (isset($d['modules_title'])) {
                $facts['modules_title'] = $d['modules_title'];
            }
            if (isset($d['process'])) {
                $facts['process_eyebrow'] = $d['process']['eyebrow'];
                $facts['process_title'] = $d['process']['title'];
                $facts['process'] = $d['process']['steps'];
            }
            $set = [
                'teaser' => $d['teaser'], 'intro' => $d['intro'], 'body_html' => '',
                'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'seo_title' => mb_substr($d['seo_title'], 0, 70), 'seo_description' => mb_substr($d['seo_desc'], 0, 170),
            ];
            if (isset($d['icon'])) {
                $set['icon'] = $d['icon'];
            }
            if ($d['parent'] !== null && empty($d['update_only'])) {
                $set['cta_label'] = $d['cta'];
                $set['cta_url'] = '/kontakt/?thema=' . $d['topic'];
            }
            $this->update('services', (int) $row['id'], $set);
            $n++;
        }
        return "$n Seiten mit ausführlichen Inhalten versehen, davon $created neu angelegt.";
    }


    // ---------------------------------------------------------------- Seiten „Über uns“ und „Kontakt“ (Update 0.9.17)

    /** Baut „Über uns“ inhaltlich aus (Abschnitte mit Karten); Stimmen, Standorte und Abschluss bleiben erhalten. */
    public function aboutPage(): string
    {
        $page = $this->db->one("SELECT id, blocks_json FROM pages WHERE slug = 'ueber-uns'");
        if ($page === null || !is_string($page['blocks_json'])) {
            return 'Seite „Über uns“ nicht vorhanden – übersprungen.';
        }
        $old = json_decode($page['blocks_json'], true);
        if (!is_array($old) || ($old[0]['type'] ?? '') !== 'page_hero') {
            return 'Seite „Über uns“ angepasst – übersprungen.';
        }
        $keep = array_values(array_filter(array_slice($old, 1), function ($n) {
            $first = $n['c'][0] ?? [];
            return ($n['f']['anchor'] ?? '') === 'stimmen'
                || (($first['type'] ?? '') === 'section_head' && ($first['f']['title'] ?? '') === 'Unsere Standorte')
                || ($first['type'] ?? '') === 'cta';
        }));
        $head = fn(string $eyebrow, string $title, string $text) => $this->node('section_head', ['eyebrow' => $eyebrow, 'title' => $title, 'text' => $text, 'link_label' => '', 'link_url' => '', 'layout' => 'stack']);
        $cards = fn(string $cols, array $items) => $this->node('cards', ['cols' => $cols, 'look' => 'card', 'level' => 'h3', 'items' => array_map(
            fn($i) => ['icon' => $i[0], 'media' => null, 'title' => $i[1], 'text' => $i[2], 'link_label' => $i[3] ?? '', 'url' => $i[4] ?? ''], $items)]);
        $hero = $old[0];
        $hero['f']['intro'] = 'Bildung ist der Schlüssel zu persönlichem und beruflichem Erfolg. Dafür arbeiten wir jeden Tag – mit Sprache, Coaching, Prüfungen und Recruiting für Menschen und Unternehmen im Ruhrgebiet.';
        $tree = [
            $hero,
            $this->node('section', ['bg' => 'none'], [
                $head('Wer wir sind', 'Bildung, die im Berufsleben ankommt', 'MEA Bildungszentrum begleitet Menschen und Unternehmen im Ruhrgebiet auf dem Weg in Arbeit, Sprache und Beruf. Wir verbinden Sprachtraining, Coaching, Prüfungen und Recruiting unter einem Dach – mit persönlicher Beratung an den Standorten Marl und Herne. Unser Anspruch: Angebote, die verständlich erklärt sind und im Alltag tatsächlich weiterhelfen.'),
                $cards('2', [
                    ['target', 'Unsere Vision', 'Wir wollen ein Anbieter hochwertiger Erwachsenenbildung sein. Dahinter steht die Überzeugung, dass Bildung der Schlüssel zu persönlichem und beruflichem Erfolg ist.'],
                    ['book-open', 'Unsere Mission', 'Wir unterstützen die persönliche und berufliche Entwicklung unserer Teilnehmenden mit wirksamen Angeboten. Drei Grundsätze leiten uns: lebenslanges Lernen, Befähigung und Weiterkommen durch praxisnahe Lernmethoden.'],
                ]),
            ]),
            $this->node('section', ['bg' => 'alt'], [
                $head('Unsere Werte', 'Woran wir uns messen lassen', 'Unsere Werte sind kein Aushang, sondern die Grundlage unserer täglichen Arbeit im Unterricht, in der Beratung und in der Zusammenarbeit mit Betrieben.'),
                $cards('3', [
                    ['badge-check', 'Qualität', 'Hohe Ansprüche an Unterricht und Begleitung: nachvollziehbare Inhalte, erfahrene Lehrkräfte und eine verlässliche Organisation.'],
                    ['sparkles', 'Innovation', 'Neue Methoden setzen wir dort ein, wo sie Lernenden wirklich helfen – nicht um ihrer selbst willen.'],
                    ['users', 'Respekt', 'Ein inklusives Lernumfeld, in dem unterschiedliche Erfahrungen, Sprachen und Lebenswege willkommen sind.'],
                    ['heart-handshake', 'Engagement', 'Persönliche Unterstützung von der ersten Anfrage bis zum nächsten Schritt – auch bei Fragen zu Förderung und Anmeldung.'],
                    ['route', 'Nachhaltigkeit', 'Lernen, das bleibt: Wir planen Lernschritte so, dass sie im Beruf und im Alltag weitertragen.'],
                    ['handshake', 'Partnerschaft', 'Wir arbeiten mit Teilnehmenden, Unternehmen, Jobcentern und Agenturen verbindlich und transparent zusammen.'],
                ]),
            ]),
            $this->node('section', ['bg' => 'none'], [
                $head('Unser Angebot', 'Vier Bereiche, ein Campus', 'Ob Sie lernen, sich beruflich orientieren, Fachkräfte gewinnen oder eine Prüfung ablegen möchten: Wir klären zuerst Ihr Ziel und empfehlen dann den passenden Bereich.'),
                $cards('2', [
                    ['graduation-cap', 'Bildungszentrum', 'Berufliche Grundlagen, Ausbildungsvorbereitung, Sprachtraining und Prüfungen.', 'Zum Bildungszentrum', '/bildungszentrum/'],
                    ['briefcase', 'Recruiting', 'Personalbedarf klären, Qualifikationen prüfen, internationale Fachkräfte beim Ankommen begleiten.', 'Zu Recruiting', '/recruiting/'],
                    ['user-check', 'Coaching & Beratung', 'Einzelcoaching, Förderung und Beratung für Teilnehmende und Jobcenter.', 'Zu Coaching & Beratung', '/coaching/'],
                    ['languages', 'Sprache & Beruf', 'Berufsdeutsch, Prüfungsvorbereitung und Sprachtraining für Unternehmen.', 'Zu Sprache & Beruf', '/sprache-beruf/'],
                ]),
            ]),
            $this->node('section', ['bg' => 'alt'], [
                $head('So arbeiten wir', 'Vom ersten Gespräch zum passenden Angebot', 'Unser Vorgehen ist bewusst einfach, damit Sie jederzeit wissen, woran Sie sind.'),
                $this->node('steps', ['items' => [
                    ['title' => 'Zuhören', 'text' => 'Wir lernen Ihr Ziel, Ihre Ausgangslage und Ihre zeitlichen Möglichkeiten kennen.'],
                    ['title' => 'Einordnen', 'text' => 'Wir besprechen Lernbedarf, mögliche Förderwege und passende Angebote verständlich und ehrlich.'],
                    ['title' => 'Planen', 'text' => 'Gemeinsam legen wir Ort, Lernform und nächste Schritte fest, bevor Sie sich entscheiden.'],
                    ['title' => 'Begleiten', 'text' => 'Wir bleiben Ansprechpartner – während des Angebots und darüber hinaus.'],
                ]]),
            ]),
            $this->node('section', ['bg' => 'none'], [
                $head('Unser Team', 'Menschen, die Fachwissen und Praxis verbinden', 'Unsere Dozentinnen, Dozenten und Coaches verbinden Fachwissen mit Praxiserfahrung und schaffen ein unterstützendes Lernumfeld. Sie kennen die Anforderungen der Betriebe im Ruhrgebiet und die Fragen, die Lernende mitbringen – und nehmen sich Zeit dafür.'),
            ]),
            ...$keep,
        ];
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->update('pages', (int) $page['id'], ['blocks_json' => $json, 'draft_json' => $json]);
        return 'Seite „Über uns“ ausgebaut.';
    }

    /** Kontaktseite nach Vorgabe: Kontaktdaten links, Formular rechts, Überschrift mit Zielhinweis. */
    public function contactPage(): string
    {
        $page = $this->db->one("SELECT id, blocks_json FROM pages WHERE slug = 'kontakt'");
        if ($page === null || !is_string($page['blocks_json'])) {
            return 'Seite „Kontakt“ nicht vorhanden – übersprungen.';
        }
        $tree = json_decode($page['blocks_json'], true);
        if (!is_array($tree)) {
            return 'Seite „Kontakt“ nicht lesbar – übersprungen.';
        }
        $walk = function (array $nodes) use (&$walk): array {
            foreach ($nodes as $i => $n) {
                $f = is_array($n['f'] ?? null) ? $n['f'] : [];
                switch ($n['type'] ?? '') {
                    case 'page_hero':
                        $f['eyebrow'] = 'MEA Bildungszentrum';
                        $f['title'] = 'Ihr Gespräch mit MEA. Ihr Anliegen im Mittelpunkt.';
                        $f['intro'] = 'Berufliche Grundlagen, Sprache, Coaching und Prüfungen: Wir besprechen Ihren passenden Weg und begleiten den nächsten Schritt. Sie erhalten eine persönliche Antwort, meist am nächsten Werktag.';
                        break;
                    case 'columns':
                        // Kontaktdaten links (schmal), Formular rechts (breit); auf dem Handy bleibt das Formular zuerst
                        $cols = array_values($n['c'] ?? []);
                        $infoFirst = ($cols[0]['c'][0]['type'] ?? '') === 'contact_info';
                        if (!$infoFirst && count($cols) === 2) {
                            $n['c'] = array_reverse($cols);
                        }
                        $f['layout'] = '2-3';
                        $f['reverse'] = false;
                        break;
                    case 'contact_form':
                        $f['title'] = 'Ihre Anfrage an MEA';
                        break;
                    case 'contact_info':
                        $f['title'] = 'Der direkte Draht zu MEA';
                        break;
                }
                $nodes[$i]['f'] = $f;
                if (isset($n['c']) && is_array($n['c'])) {
                    $nodes[$i]['c'] = $walk($n['c']);
                }
            }
            return $nodes;
        };
        $json = json_encode($walk($tree), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->update('pages', (int) $page['id'], ['blocks_json' => $json, 'draft_json' => $json]);
        return 'Seite „Kontakt“ angepasst.';
    }

    // ---------------------------------------------------------------- Fördermöglichkeiten (Fußbereich)

    private function fundingPage(): string
    {
        if ($this->db->value("SELECT 1 FROM pages WHERE slug = 'foerderung'") !== null) {
            return 'Seite „Fördermöglichkeiten“ vorhanden.';
        }
        $lead = 'Bildungsgutschein, AVGS und Sprachförderung im Überblick: Wir klären Kosten, Zugang und Voraussetzungen vor dem Beginn. Über eine öffentliche Förderung entscheidet die zuständige Stelle.';
        $tree = [
            $this->node('page_hero', ['eyebrow' => 'MEA Campus', 'title' => 'Erst den Förderweg klären, dann den Einstieg planen', 'intro' => $lead,
                'media' => null, 'crumb' => 'Fördermöglichkeiten', 'parent_label' => 'Campus', 'parent_url' => '/campus/']),
            $this->node('section', ['bg' => 'none'], [
                $this->node('section_head', ['eyebrow' => 'Die wichtigsten Begriffe', 'title' => 'Welcher Weg passt zu welchem Ziel?',
                    'text' => 'Ein Gutschein ist kein frei einsetzbares Zahlungsmittel für jedes Bildungsangebot. Er beschreibt einen bestimmten Förderzweck und kann zeitliche, regionale oder inhaltliche Grenzen enthalten.', 'link_label' => '', 'link_url' => '', 'layout' => 'stack']),
                $this->node('cards', ['cols' => '3', 'look' => 'card', 'level' => 'h3', 'items' => [
                    ['icon' => 'file-text', 'media' => null, 'title' => 'Bildungsgutschein', 'text' => 'Förderweg für berufliche Weiterbildung. Bildungsziel, zugelassener Träger und konkrete Maßnahme müssen zusammenpassen.', 'link_label' => '', 'url' => ''],
                    ['icon' => 'user-check', 'media' => null, 'title' => 'Aktivierungs- und Vermittlungsgutschein (AVGS)', 'text' => 'Gutschein für Coaching und Vermittlung. Er ist nicht dasselbe wie ein Bildungsgutschein.', 'link_label' => 'Zum Coaching', 'url' => '/coaching/foerderung/'],
                    ['icon' => 'languages', 'media' => null, 'title' => 'Sprachförderung', 'text' => 'BAMF-Berufssprachkurse folgen eigenen Teilnahme- und Zulassungsregeln. Wir erklären, was für Ihr Ziel in Frage kommt.', 'link_label' => 'Zu Sprache & Beruf', 'url' => '/sprache-beruf/'],
                ]]),
            ]),
            $this->node('section', ['bg' => 'alt'], [
                $this->node('section_head', ['eyebrow' => 'Ablauf', 'title' => 'So gehen wir vor',
                    'text' => 'Wir klären zuerst Ihr Ziel, dann das konkrete Angebot und seine Zulassung. Die Finanzierung stimmen Sie bitte vor dem Beginn mit der zuständigen Stelle ab. Öffentliche Förderung ist keine automatische Zusage.', 'link_label' => '', 'link_url' => '', 'layout' => 'stack']),
            ]),
            $this->node('section', ['bg' => 'none'], [
                $this->node('cta', ['title' => 'Fragen zu Angebot und Förderung?', 'text' => 'Wir beraten Sie gern – telefonisch, per WhatsApp oder persönlich.',
                    'btn_label' => 'Nachricht schreiben', 'btn_url' => '/kontakt/', 'phone' => true]),
            ]),
        ];
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->insert('pages', [
            'slug' => 'foerderung', 'title' => 'Fördermöglichkeiten', 'intro' => mb_substr($lead, 0, 500), 'template' => 'default', 'status' => 'published',
            'is_system' => 1, 'blocks_json' => $json, 'draft_json' => $json,
            'seo_title' => 'Fördermöglichkeiten: Bildungsgutschein, AVGS, Sprachförderung | MEA',
            'seo_description' => 'Bildungsgutschein, AVGS und Sprachförderung verständlich erklärt: Kosten, Zugang und Voraussetzungen vor dem Beginn klären.',
        ]);
        return 'Seite „Fördermöglichkeiten“ angelegt.';
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

    // ---------------------------------------------------------------- Menüs

    private function menus(): string
    {
        $main = $this->db->value("SELECT id FROM menus WHERE menu_key = 'main'");
        $footer = $this->db->value("SELECT id FROM menus WHERE menu_key = 'footer'");
        if ($main === null || $footer === null) {
            return 'Menüs nicht vorhanden – übersprungen.';
        }
        $add = fn(int $menu, string $label, string $url, int $sort, ?int $parent = null, ?string $desc = null): int
            => $this->db->insert('menu_items', ['menu_id' => $menu, 'parent_id' => $parent, 'label' => $label, 'url' => $url, 'sort_order' => $sort, 'description' => $desc]);
        $this->db->run('DELETE FROM menu_items WHERE menu_id IN (?, ?)', [$main, $footer]); // Untereinträge fallen per Fremdschlüssel mit
        $m = (int) $main;
        $add($m, 'Startseite', '/', 0);
        $campus = $add($m, 'Campus', '/campus/', 1);
        $groups = [
            ['Bildungszentrum', '/bildungszentrum/', 'Lernen. Qualifizieren. Weiterkommen.', [
                ['Übersicht', '/bildungszentrum/', 'Lernen, das den nächsten Schritt möglich macht'],
                ['Ausbildung', '/bildungszentrum/ausbildung/', 'Grundlagen für den Einstieg in eine Ausbildung'],
                ['Sprachtraining', '/bildungszentrum/sprachtraining/', 'Die Sprache lernen, die der Beruf verlangt'],
                ['Prüfungen', '/bildungszentrum/pruefungen/', 'Vorbereitung und Prüfungsangebote klären'],
                ['Beratungstermin anfragen', '/kontakt/?thema=bildungszentrum', null]]],
            ['Recruiting', '/recruiting/', 'Menschen. Unternehmen. Perspektiven.', [
                ['Onboarding', '/recruiting/onboarding/', 'Internationale Mitarbeitende beim Ankommen begleiten'],
                ['Für Unternehmen', '/recruiting/unternehmen/', 'Personalbedarf und Zusammenarbeit abstimmen'],
                ['Personalbedarf besprechen', '/kontakt/?thema=fachkraefte', null]]],
            ['Coaching & Beratung', '/coaching/', 'Orientierung. Klarheit. Nächster Schritt.', [
                ['Einzelcoaching', '/coaching/', 'Drei 1:1-Angebote für Ihre Situation'],
                ['Förderung & AZAV', '/coaching/foerderung/', 'AVGS, Bildungsgutschein und AZAV erklärt'],
                ['Für Teilnehmende', '/coaching/teilnehmende/', 'Ihr Anliegen und die nächsten Schritte'],
                ['Für Jobcenter', '/coaching/jobcenter/', 'Umfang, Dauer und Zulassung'],
                ['Beratung anfragen', '/kontakt/?thema=avgs', null]]],
            ['Sprache & Beruf', '/sprache-beruf/', 'Verstehen. Sprechen. Im Beruf ankommen.', [
                ['Sprachangebote', '/sprache-beruf/', 'Deutsch im Arbeitsalltag'],
                ['Berufsdeutsch', '/sprache-beruf/berufsdeutsch/', 'Arbeitsaufträge, Rückfragen, Fachsprache'],
                ['Prüfungen', '/pruefungen/', 'telc A1 bis C1, Termine und Buchung'],
                ['Prüfungsvorbereitung', '/pruefungen/vorbereitung/', 'Gezielt auf die Prüfung vorbereiten'],
                ['Für Unternehmen', '/sprache-beruf/unternehmen/', 'Sprachtraining für den Betriebsalltag'],
                ['Beratung anfragen', '/kontakt/?thema=sprachkurs', null]]],
        ];
        foreach ($groups as $gi => [$label, $url, $desc, $items]) {
            $g = $add($m, $label, $url, $gi + 1, $campus, $desc);
            foreach ($items as $ii => [$l, $u, $d]) {
                $add($m, $l, $u, $ii + 1, $g, $d);
            }
        }
        $add($m, 'Über uns', '/ueber-uns/', 2);
        $add($m, 'Standorte', '/standorte/', 3);
        $add($m, 'Kontakt', '/kontakt/', 4);
        foreach ([['Fördermöglichkeiten', '/foerderung/'], ['Häufige Fragen', '/faq/'], ['Preise', '/pruefungen/preise/'], ['Karriere', '/karriere/'], ['Kontakt', '/kontakt/']] as $i => [$a, $b]) {
            $add((int) $footer, $a, $b, $i + 1);
        }
        return 'Hauptmenü (Campus-Megamenü, Über uns, Standorte, Kontakt) und Footer-Menü neu aufgebaut.';
    }

    /** @param array<string,mixed> $set */
    private function update(string $table, int $id, array $set): void
    {
        $sql = "UPDATE $table SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?';
        $this->db->run($sql, [...array_values($set), $id]);
    }
}
