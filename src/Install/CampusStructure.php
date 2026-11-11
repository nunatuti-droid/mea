<?php
declare(strict_types=1);

namespace Mea\Install;

use Mea\Core\Database;
use Mea\Module\Builder\BlockTypes;
use Mea\Module\Builder\TreeValidator;

/**
 * Update 0.9.12: neue Website-Struktur mit „MEA Campus“ (vier Bereiche), „Prüfungen“ mit Prüfungsvorbereitung
 * und „Über uns“ im Footer. Ändert nur Daten (Adressen, Menüs, Zuordnung, Meta-Angaben); Texte bleiben erhalten.
 * Wiederholbar: bereits umgestellte Einträge werden übersprungen. Läuft nach den Startdaten und einmalig nach einem Update
 * (ContentPatches). Weiterleitungen alter Adressen gibt es bewusst nicht; die Weiterleitungen der alten
 * WordPress-Seite werden entfernt.
 */
final class CampusStructure
{
    /** Alte Adresse => neue Adresse (Pfade ohne Eltern-Teil werden in Texten, Menüs und Seitenbausteinen ersetzt). */
    private const URLS = [
        '/avgs/stabilisierung/' => '/coaching-beratung/job-mentoring/',
        '/avgs/' => '/coaching-beratung/',
        '/beschaeftigten-qualifizierung/' => '/bildungszentrum/',
        '/fachkraeftegewinnung/' => '/recruiting/',
        '/onboarding/' => '/recruiting/onboarding/',
        '/sprachkurse/' => '/sprache-beruf/',
        '/telc-pruefungszentrum/' => '/pruefungen/',
        '/leistungen/' => '/campus/',
        '/preise/' => '/pruefungen/preise/',
    ];

    /** Verschobener Abschnitt der Prüfungsseite (wie in den Startdaten). */
    private const PREP_HTML = '<h2>Intensive Auffrischungskurse und gezielte Prüfungsvorbereitung</h2><p>Für alle Stufen des Gemeinsamen Europäischen Referenzrahmens (A1 bis C2) bieten wir Vorbereitungskurse an, zum Beispiel einen 6-wöchigen Intensivkurs oder einen 2-wöchigen Auffrischungskurs.</p>';
    private const PREP_TEASER = '<h2>Prüfungsvorbereitung</h2><p>Gezielte Vorbereitung für alle Stufen von A1 bis C2: Intensiv- und Auffrischungskurse. <a href="/pruefungen/vorbereitung/">Zur Prüfungsvorbereitung</a></p>';

    /** Importierte Weiterleitungen der alten WordPress-Seite (werden entfernt). */
    public const WORDPRESS_REDIRECTS = [
        '/unser-leitbild/', '/karriere-dozenten/', '/hier-herunterladen/', '/product/telc-sprachpruefung-deutsch-a2-b1/', '/cookie-richtlinie-eu/', '/telc/',
        '/weiterbildungen/', '/bewerberprofile/', '/courses/', '/shop-2/', '/cart/', '/checkout/', '/my-account/', '/product-category/a2-b1/',
        '/product-category/booking/', '/faqs/', '/contact-me/', '/privacy-policy-2/', '/terms-condition/', '/courses/starting-seo-as-your-home-based-business/',
        '/sample-page/', '/lp-checkout/', '/lp-checkout-2/', '/lp-profile/', '/lp-profile-2/', '/instructors/', '/instructors-2/', '/instructor/', '/instructor-1/',
        '/instructor-2-2/', '/instructor-3/', '/instructor-4/', '/lp-become-a-teacher/', '/lp-become-a-teacher-2/', '/lp-term-conditions/', '/lp-term-conditions-2/',
        '/user-register/', '/user-register-2/', '/user-login/', '/user-login-2/', '/forgot-password/', '/forgot-password-2/', '/reset-password/', '/reset-password-2/',
        '/user-account/', '/user-account-2/', '/component/', '/component/footer/', '/component/brand/', '/component/cta/', '/component/counterup/',
        '/component/accordion/', '/component/video-popup/', '/component/features/', '/component/why-choose/', '/component/services/', '/component/other-sections/',
        '/coming-soon/', '/purchase-guide/', '/pricing-table/', '/gallery-grid/', '/gallery-masonry/', '/cart-2/', '/checkout-2/', '/blog/', '/event-style-1/',
        '/courses-2/', '/my-account-2/', '/elementor-18013/', '/course-category/business/', '/course-category/motivation/', '/course-category/programming/',
        '/course-category/online-art/', '/course-category/fitness/', '/course-category/digital-marketing/', '/course-category/photography/',
        '/course-tag/development/', '/courses/grow-personal-financial-security-thinking-principles-2/',
    ];

    public function __construct(private Database $db) {}

    /** @return list<string> Protokoll */
    public function run(): array
    {
        $log = [];
        $this->db->transaction(function () use (&$log): void {
            $log[] = $this->services();
            $log[] = $this->pages();
            $log[] = $this->menus();
            $log[] = $this->redirects();
            $log[] = $this->links();
        });
        return $log;
    }

    // ---------------------------------------------------------------- Leistungen

    private function services(): string
    {
        $defs = [
            'beschaeftigten-qualifizierung' => ['slug' => 'bildungszentrum', 'name' => 'Bildungszentrum', 'sort' => 1,
                'teaser' => 'Berufliche Grundlagen stärken, neue Fähigkeiten entwickeln und den Einstieg in Ausbildung und Beruf vorbereiten.',
                'seo_title' => 'Bildungszentrum: Qualifizierung für Beschäftigte | MEA Marl',
                'seo_description' => 'Praxisnahe Qualifizierung für Beschäftigte und Unterstützung bei der Personalauswahl – Weiterbildung am Bedarf von Arbeitsmarkt und Betrieb.'],
            'fachkraeftegewinnung' => ['slug' => 'recruiting', 'name' => 'Recruiting', 'sort' => 2,
                'teaser' => 'Fachkräftegewinnung, Talentvermittlung und internationales Onboarding – mit dem Personalbedarf Ihres Unternehmens im Mittelpunkt.',
                'seo_title' => 'Recruiting & Fachkräftegewinnung aus dem Ausland | MEA',
                'seo_description' => 'Fachkräfte für Industrie, Handwerk, Logistik und Pflege: Bedarfsanalyse, Rekrutierung auf dem Balkan, Anerkennung und Onboarding aus einer Hand.'],
            'avgs' => ['slug' => 'coaching-beratung', 'name' => 'Coaching & Beratung', 'sort' => 3,
                'teaser' => 'Berufliche Ziele klären, persönliche Stärken erkennen und den nächsten Schritt mit individueller Begleitung planen.',
                'seo_title' => 'Coaching & Beratung mit AVGS in Marl und Herne | MEA',
                'seo_description' => 'Individuelles 1:1-Coaching mit Aktivierungs- und Vermittlungsgutschein (AVGS): MEA Plus, Job-Mentoring und Betreuung nach § 16k SGB II.'],
            'sprachkurse' => ['slug' => 'sprache-beruf', 'name' => 'Sprache & Beruf', 'sort' => 4,
                'teaser' => 'Berufsbezogenes Deutsch, praxisnahes Sprachtraining und Prüfungsvorbereitung für Ausbildung, Betrieb und Alltag.',
                'seo_title' => 'Deutschkurse & Kommunikationstraining Marl und Herne | MEA',
                'seo_description' => 'Deutsch für Alltag und Beruf: Einzel- und Gruppentraining, Wochenend- und Online-Kurse sowie Vorbereitung auf telc-Prüfungen A1 bis C1.'],
        ];
        $n = 0;
        foreach ($defs as $old => $d) {
            $row = $this->db->one('SELECT id FROM services WHERE slug = ? AND parent_id IS NULL', [$old]);
            if ($row === null || $this->db->value('SELECT 1 FROM services WHERE slug = ? AND parent_id IS NULL', [$d['slug']]) !== null) {
                continue;
            }
            $this->db->run(
                'UPDATE services SET slug = ?, title = ?, menu_title = ?, sort_order = ?, teaser = ?, seo_title = ?, seo_description = ? WHERE id = ?',
                [$d['slug'], $d['name'], $d['name'], $d['sort'], $d['teaser'], $d['seo_title'], $d['seo_description'], $row['id']],
            );
            $n++;
        }
        // Onboarding: Unterseite von Recruiting
        $rec = $this->db->value("SELECT id FROM services WHERE slug = 'recruiting' AND parent_id IS NULL");
        $onb = $this->db->one("SELECT id FROM services WHERE slug = 'onboarding' AND parent_id IS NULL");
        if ($rec !== null && $onb !== null) {
            $this->db->run('UPDATE services SET parent_id = ?, sort_order = 1 WHERE id = ?', [$rec, $onb['id']]);
        }
        // Stabilisierung heißt im Menü schon Job-Mentoring
        $this->db->run("UPDATE services SET slug = 'job-mentoring' WHERE slug = 'stabilisierung' AND parent_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM services WHERE slug = 'job-mentoring') x)");
        // Die Prüfungen sind jetzt ein eigener Menüpunkt, nicht mehr eine Leistungskarte
        $this->db->run("UPDATE services SET status = 'archived' WHERE slug = 'telc-pruefungszentrum' AND parent_id IS NULL");
        return "Leistungen umgestellt: $n Bereiche.";
    }

    // ---------------------------------------------------------------- Seiten

    private function pages(): string
    {
        // Campus-Übersicht statt „Leistungen“
        $id = $this->rename('leistungen', 'campus');
        if ($id !== null) {
            $this->db->run('UPDATE pages SET title = ?, seo_title = ?, seo_description = ? WHERE id = ?', [
                'MEA Campus', 'MEA Campus – Bildung, Recruiting, Coaching, Sprache | Marl & Herne',
                'Der MEA Campus bündelt vier Bereiche: Bildungszentrum, Recruiting, Coaching & Beratung sowie Sprache & Beruf. Wählen Sie Ihren Weg.', $id,
            ]);
            $this->editTrees($id, function (array &$tree): void {
                $this->walkNodes($tree, function (array &$n): void {
                    $f = &$n['f'];
                    if (($n['type'] ?? '') === 'page_hero' && ($f['eyebrow'] ?? '') === 'Was wir für Sie tun') {
                        $f['eyebrow'] = 'MEA Campus';
                        $f['crumb'] = 'Campus';
                        $f['title'] = 'Ein Campus. Vier Wege nach vorn.';
                    }
                    if (($n['type'] ?? '') === 'heading' && ($f['text'] ?? '') === 'Alle Leistungen') {
                        $f['text'] = 'Die vier Campus-Bereiche';
                    }
                });
            });
        }
        // Prüfungsübersicht
        $exam = $this->rename('telc-pruefungszentrum', 'pruefungen');
        if ($exam !== null) {
            $this->db->run('UPDATE pages SET title = ?, seo_title = ?, seo_description = ? WHERE id = ?', [
                'telc-Prüfungszentrum', 'telc-Prüfungen in Herne: Termine, Preise, Anmeldung | MEA',
                'Lizenziertes telc-Prüfungszentrum: Deutschprüfungen nach GER, aktuelle Termine, Preise und freie Plätze. Jetzt online anmelden.', $exam,
            ]);
            // Abschnitt „Prüfungsvorbereitung“ wandert auf die neue Seite, hier bleibt ein Hinweis
            $body = (string) $this->db->value('SELECT body_html FROM pages WHERE id = ?', [$exam]);
            $this->db->run('UPDATE pages SET body_html = ? WHERE id = ?', [str_replace(self::PREP_HTML, self::PREP_TEASER, $body), $exam]);
            $this->editTrees($exam, function (array &$tree): void {
                $this->walkNodes($tree, function (array &$n): void {
                    if (isset($n['f']['html']) && is_string($n['f']['html'])) {
                        $n['f']['html'] = str_replace(self::PREP_HTML, self::PREP_TEASER, $n['f']['html']);
                    }
                });
            });
        }
        $this->db->run("UPDATE pages SET seo_title = 'Preise für telc-Prüfungen | MEA Bildungszentrum Herne' WHERE slug = 'preise' AND seo_title = 'Preise für telc-Prüfungen – MEA Bildungszentrum'");
        $this->prepPage();
        $this->home();
        return 'Seiten umgestellt (Campus, Prüfungen, Prüfungsvorbereitung, Startseite).';
    }

    private function rename(string $old, string $new): ?int
    {
        $id = $this->db->value('SELECT id FROM pages WHERE slug = ?', [$old]);
        if ($id === null || $this->db->value('SELECT 1 FROM pages WHERE slug = ?', [$new]) !== null) {
            return null;
        }
        $this->db->run('UPDATE pages SET slug = ? WHERE id = ?', [$new, $id]);
        return (int) $id;
    }

    private function home(): void
    {
        $id = $this->db->value('SELECT id FROM pages WHERE is_home = 1');
        if ($id === null) {
            return;
        }
        $this->editTrees((int) $id, function (array &$tree): void {
            $this->walkNodes($tree, function (array &$n): void {
                $f = &$n['f'];
                if (($n['type'] ?? '') === 'hero_home' && ($f['btn1_label'] ?? '') === 'Unsere Leistungen') {
                    $f['btn1_label'] = 'Zum Campus';
                }
                // Abschnittskopf über den Leistungskarten (nur, wenn noch im Auslieferungszustand)
                if (($n['type'] ?? '') === 'section_head' && ($f['title'] ?? '') === 'Bildung, Integration und Fachkräftegewinnung') {
                    $f['eyebrow'] = 'MEA Campus';
                    $f['title'] = 'Ein Campus. Vier Wege nach vorn.';
                    $f['text'] = 'Vier Hubs, vier Schwerpunkte. Wählen Sie den passenden Bereich – dort finden Sie Angebote, Informationen und Ihren direkten Kontakt.';
                    $f['link_label'] = '';
                    $f['link_url'] = '';
                    $f['layout'] = 'stack';
                }
            });
        });
    }

    /** Neue Seite „Prüfungsvorbereitung“ als Entwurf. Dauer, Termine und Preise sind Beispielwerte. */
    private function prepPage(): void
    {
        if ($this->db->value("SELECT 1 FROM pages WHERE slug = 'pruefungsvorbereitung'") !== null) {
            return;
        }
        $exam = $this->db->one("SELECT hero_media_id FROM pages WHERE slug = 'pruefungen'");
        $media = $exam !== null && $exam['hero_media_id'] !== null ? (int) $exam['hero_media_id'] : null;
        $lead = 'Für alle Stufen des Gemeinsamen Europäischen Referenzrahmens (A1 bis C2) bieten wir Vorbereitungskurse an, zum Beispiel einen 6-wöchigen Intensivkurs oder einen 2-wöchigen Auffrischungskurs.';
        $tree = [
            $this->node('page_hero', ['eyebrow' => 'Prüfungen', 'title' => 'Prüfungsvorbereitung für telc-Prüfungen', 'intro' => $lead, 'media' => $media,
                'crumb' => 'Prüfungsvorbereitung', 'parent_label' => 'Prüfungen', 'parent_url' => '/pruefungen/']),
            $this->node('section', ['bg' => 'none'], [
                $this->node('text', ['html' => '<p><strong>Hinweis zum Entwurf:</strong> Dauer, Termine und Preise unten sind Beispielwerte und müssen vor der Veröffentlichung durch die echten Angaben ersetzt werden.</p>']),
            ]),
            $this->node('section', ['bg' => 'none'], [
                $this->node('section_head', ['eyebrow' => 'Kurse', 'title' => 'Intensive Auffrischungskurse und gezielte Prüfungsvorbereitung',
                    'text' => 'Wählen Sie den Kurs, der zu Ihrem Niveau und Ihrer Zeit passt. Alle Angaben sind Beispiele.', 'link_label' => '', 'link_url' => '', 'layout' => 'stack']),
                $this->node('cards', ['cols' => '2', 'look' => 'card', 'level' => 'h3', 'items' => [
                    ['icon' => 'clipboard-check', 'media' => null, 'title' => 'Intensivkurs (Beispiel)', 'text' => 'Dauer: 6 Wochen, Montag bis Freitag. Nächster Start: 05.01.2027. Preis: 480 €. Alle Prüfungsteile: Lesen, Hören, Schreiben, Sprechen.', 'link_label' => '', 'url' => ''],
                    ['icon' => 'calendar-days', 'media' => null, 'title' => 'Auffrischungskurs (Beispiel)', 'text' => 'Dauer: 2 Wochen, vormittags. Nächster Start: 02.02.2027. Preis: 190 €. Gezielte Wiederholung kurz vor der Prüfung.', 'link_label' => '', 'url' => ''],
                ]]),
            ]),
            $this->node('section', ['bg' => 'alt'], [
                $this->node('section_head', ['eyebrow' => 'Ihr Weg', 'title' => 'Von der Vorbereitung zur Prüfung',
                    'text' => 'Vorbereitung und Prüfung bekommen Sie bei uns aus einer Hand: Kurse für Alltag und Beruf finden Sie unter Sprache & Beruf, freie Prüfungstermine direkt bei den Prüfungen.', 'link_label' => '', 'link_url' => '', 'layout' => 'stack']),
                $this->node('text', ['html' => '<p><a href="/sprache-beruf/">Zu Sprache &amp; Beruf</a> · <a href="/pruefungen/">Zu den Prüfungen und Terminen</a> · <a href="/faq/">Häufige Fragen</a></p>']),
            ]),
            $this->node('section', ['bg' => 'none'], [
                $this->node('cta', ['title' => 'Welche Vorbereitung passt zu Ihnen?', 'text' => 'Wir beraten Sie gerne zu Niveau, Kurs und Prüfungstermin – telefonisch, per WhatsApp oder persönlich.',
                    'btn_label' => 'Nachricht schreiben', 'btn_url' => '/kontakt/?thema=sprachkurs', 'phone' => true]),
            ]),
        ];
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->insert('pages', [
            'slug' => 'pruefungsvorbereitung', 'title' => 'Prüfungsvorbereitung', 'intro' => mb_substr($lead, 0, 500), 'template' => 'default', 'status' => 'draft',
            'is_system' => 1, 'hero_media_id' => $media, 'blocks_json' => '[]', 'draft_json' => $json,
            'seo_title' => 'Prüfungsvorbereitung telc: Intensiv- & Auffrischungskurse | MEA',
            'seo_description' => 'Gezielte Vorbereitung auf telc-Prüfungen von A1 bis C2: Intensivkurs über 6 Wochen, Auffrischungskurs über 2 Wochen. Termine auf Anfrage.',
        ]);
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
        $this->db->run('DELETE FROM menu_items WHERE menu_id IN (?, ?)', [$main, $footer]);
        $add((int) $main, 'Startseite', '/', 0);
        $c = $add((int) $main, 'Campus', '/campus/', 1);
        $add((int) $main, 'Campus im Überblick', '/campus/', 1, $c, 'Alle vier Bereiche auf einen Blick');
        $add((int) $main, 'Bildungszentrum', '/bildungszentrum/', 2, $c, 'Lernen & Qualifizieren');
        $add((int) $main, 'Recruiting', '/recruiting/', 3, $c, 'Fachkräfte & Onboarding');
        $add((int) $main, 'Coaching & Beratung', '/coaching-beratung/', 4, $c, '1:1-Coaching mit Gutschein');
        $add((int) $main, 'Sprache & Beruf', '/sprache-beruf/', 5, $c, 'Deutsch im Arbeitsalltag');
        $p = $add((int) $main, 'Prüfungen', '/pruefungen/', 2);
        $add((int) $main, 'Prüfungen', '/pruefungen/', 1, $p, 'telc-Prüfungen online buchen');
        $add((int) $main, 'Prüfungsvorbereitung', '/pruefungen/vorbereitung/', 2, $p, 'Kurse zur Prüfung');
        $add((int) $main, 'Standorte', '/standorte/', 3);
        $add((int) $main, 'Kontakt', '/kontakt/', 4);
        foreach ([['Über uns', '/ueber-uns/'], ['Karriere', '/karriere/'], ['Häufige Fragen', '/faq/'], ['Preise', '/pruefungen/preise/'], ['Kontakt', '/kontakt/']] as $i => [$a, $b]) {
            $add((int) $footer, $a, $b, $i + 1);
        }
        return 'Haupt- und Footer-Menü neu aufgebaut (Über uns im Footer).';
    }

    // ---------------------------------------------------------------- Weiterleitungen

    private function redirects(): string
    {
        $n = (int) $this->db->run("DELETE FROM redirects WHERE source_path IN ('/feed/', '/comments/feed/') OR source_path LIKE '/%*'");
        foreach (array_chunk(self::WORDPRESS_REDIRECTS, 50) as $chunk) {
            $n += $this->db->run('DELETE FROM redirects WHERE source_path IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
        return "$n Weiterleitungen der alten WordPress-Seite entfernt.";
    }

    // ---------------------------------------------------------------- Verweise in Texten, Bausteinen und Einstellungen

    private function links(): string
    {
        foreach ([
            ['services', 'id', ['intro', 'teaser', 'body_html', 'cta_url']],
            ['pages', 'id', ['intro', 'body_html']],
            ['faqs', 'id', ['answer_html']],
        ] as [$table, $key, $cols]) {
            foreach ($this->db->all("SELECT $key, " . implode(',', $cols) . " FROM $table") as $row) {
                $set = [];
                foreach ($cols as $c) {
                    if (is_string($row[$c] ?? null) && ($new = $this->text($row[$c])) !== $row[$c]) {
                        $set[$c] = $new;
                    }
                }
                $this->update($table, $key, $row[$key], $set);
            }
        }
        $json = [['services', 'id', ['facts_json']], ['pages', 'id', ['blocks_json', 'draft_json']], ['builder_components', 'id', ['tree_json', 'draft_json']]];
        foreach ($json as [$table, $key, $cols]) {
            foreach ($this->db->all("SELECT $key, " . implode(',', $cols) . " FROM $table") as $row) {
                $set = [];
                foreach ($cols as $c) {
                    $data = is_string($row[$c] ?? null) ? json_decode($row[$c], true) : null;
                    if (is_array($data)) {
                        $new = json_encode($this->deep($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        if ($new !== json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) {
                            $set[$c] = $new;
                        }
                    }
                }
                $this->update($table, $key, $row[$key], $set);
            }
        }
        foreach ($this->db->all('SELECT setting_key, value_json FROM settings') as $row) {
            $data = json_decode((string) $row['value_json'], true);
            if (is_array($data)) {
                $new = json_encode($this->deep($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($new !== json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) {
                    $this->db->run('UPDATE settings SET value_json = ? WHERE setting_key = ?', [$new, $row['setting_key']]);
                }
            }
        }
        return 'Interne Verweise auf die neuen Adressen umgestellt.';
    }

    /** @param array<string,mixed> $set */
    private function update(string $table, string $key, mixed $id, array $set): void
    {
        if ($set === []) {
            return;
        }
        $sql = "UPDATE $table SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . " WHERE $key = ?";
        $this->db->run($sql, [...array_values($set), $id]);
    }

    private function text(string $s): string
    {
        foreach (self::URLS as $old => $new) {
            $s = preg_replace('#(?<![\w/.\-])' . preg_quote($old, '#') . '#', $new, $s) ?? $s;
        }
        return $s;
    }

    private function deep(mixed $v): mixed
    {
        if (is_string($v)) {
            return $this->text($v);
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $v[$k] = $this->deep($x);
            }
        }
        return $v;
    }

    // ---------------------------------------------------------------- Hilfen für Seitenbäume

    /** @param \Closure(list<array<string,mixed>>):void $fn */
    private function editTrees(int $pageId, \Closure $fn): void
    {
        $row = $this->db->one('SELECT blocks_json, draft_json FROM pages WHERE id = ?', [$pageId]);
        foreach (['blocks_json', 'draft_json'] as $col) {
            $tree = is_string($row[$col] ?? null) ? json_decode($row[$col], true) : null;
            if (!is_array($tree)) {
                continue;
            }
            $fn($tree);
            $this->db->run("UPDATE pages SET $col = ? WHERE id = ?", [json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $pageId]);
        }
    }

    /** @param array<int,array<string,mixed>> $nodes @param \Closure(array<string,mixed>):void $fn */
    private function walkNodes(array &$nodes, \Closure $fn): void
    {
        foreach ($nodes as &$n) {
            if (!is_array($n)) {
                continue;
            }
            $fn($n);
            if (isset($n['c']) && is_array($n['c'])) {
                $this->walkNodes($n['c'], $fn);
            }
        }
    }
}
