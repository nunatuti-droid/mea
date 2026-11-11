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
            $log[] = $this->subpages();
            $log[] = $this->fundingPage();
            $log[] = $this->menus();
            $log[] = (new LinkRewriter($this->db, self::URLS))->run();
        });
        return $log;
    }

    // ---------------------------------------------------------------- Bereiche (Übersichtsseiten)

    private function hubs(): string
    {
        // Coaching: neue Adresse /coaching/ (vorher /coaching-beratung/)
        if ($this->db->value("SELECT 1 FROM services WHERE slug = 'coaching-beratung' AND parent_id IS NULL") !== null
            && $this->db->value("SELECT 1 FROM services WHERE slug = 'coaching' AND parent_id IS NULL") === null) {
            $this->db->run("UPDATE services SET slug = 'coaching', menu_title = 'Coaching & Beratung' WHERE slug = 'coaching-beratung' AND parent_id IS NULL");
        }
        $hubs = [
            'bildungszentrum' => [
                'teaser' => 'Berufliche Grundlagen stärken, Sprache aufbauen und den Einstieg in Ausbildung und Beruf vorbereiten.',
                'intro' => 'Wir verbinden Sprache, berufliche Orientierung und Grundlagen für Ausbildung und Arbeit – in Gruppen mit 10 bis 15 Personen oder im Einzelsetting. Im Gespräch klären wir, was Sie bereits mitbringen und woran Sie als Nächstes arbeiten.',
                'body' => '<h2>Das richtige lernen – nicht alles</h2><p>Vielleicht möchten Sie eine Ausbildung beginnen, Arbeitsaufträge besser verstehen oder nach einer Pause beruflich wieder einsteigen. Wir besprechen Ihr Ziel und ordnen die nächsten Lernschritte. Sprachkenntnisse und berufliche Erfahrungen betrachten wir dabei gemeinsam.</p>'
                    . '<h2>Berufliche Grundlagen</h2><p>Aufgaben lesen, Informationen ordnen und Arbeitsabläufe erklären: Diese Fähigkeiten helfen beim Einstieg in gewerbliche und technische Tätigkeiten. Welche Grundlagen für Ihr Ziel wichtig sind, klären wir im Beratungsgespräch.</p>'
                    . '<h2>Gemeinsam üben oder individuell vertiefen</h2><p>Gruppen mit 10 bis 15 Teilnehmenden bieten Austausch und gemeinsame Übungen. In der Einzelbegleitung steht Ihr persönlicher Lernweg im Mittelpunkt.</p>',
                'facts' => ['topics_eyebrow' => 'Ihr Einstieg', 'topics_title' => 'Wählen Sie Ihr Thema im Bildungszentrum'],
            ],
            'recruiting' => [
                'teaser' => 'Fachkräftegewinnung, Talentvermittlung und internationales Onboarding – mit dem Personalbedarf Ihres Unternehmens im Mittelpunkt.',
                'intro' => 'Wir unterstützen Unternehmen bei der Gewinnung internationaler Fachkräfte, besonders aus dem Balkan, mit Blick auf Gewerbe, Technik und Ausbildung im Ruhrgebiet. Bedarf, Qualifikationen und Sprachniveau stimmen wir mit beiden Seiten ab.',
                'body' => null,
                'facts' => ['topics_eyebrow' => 'Ihr Einstieg', 'topics_title' => 'Onboarding und Zusammenarbeit'],
            ],
            'coaching' => [
                'teaser' => 'Berufliche Ziele klären, persönliche Stärken erkennen und den nächsten Schritt mit individueller Begleitung planen.',
                'intro' => 'MEA bietet drei berufsbezogene 1:1-Angebote: MEA Plus (120 UE, bis zu 3 Monate), Stabilisierung von Beschäftigungsverhältnissen (72 UE, bis zu 6 Monate) und Ganzheitliche Betreuung nach § 16k SGB II (310 UE, bis zu 12 Monate). Welches Angebot zu Ihrer Situation passt, klären wir im persönlichen Gespräch.',
                'body' => null,
                'facts' => ['topics_eyebrow' => 'Mehr erfahren', 'topics_title' => 'Förderung, Teilnehmende und Jobcenter'],
            ],
            'sprache-beruf' => [
                'teaser' => 'Berufsbezogenes Deutsch, praxisnahes Sprachtraining und Prüfungsvorbereitung für Ausbildung, Betrieb und Alltag.',
                'intro' => 'Sprache verbindet, was jemand kann, mit dem, was im Beruf sichtbar wird. Wir richten das Training auf konkrete Situationen aus: im Betrieb, auf dem Weg in die Ausbildung und bei der beruflichen Neuorientierung.',
                'body' => '<h2>Welche Sprache brauchen Sie als Nächstes?</h2><p>Ein allgemeiner Sprachstand zeigt noch nicht, welche Unterstützung im Beruf hilft. Wir fragen nach den Situationen, die Ihnen schwerfallen, und nach Ihrem Ziel – und betrachten Sprechen, Hören, Lesen und Schreiben. Auch Unternehmen können mit Beispielen aus dem Arbeitsalltag in die Beratung einsteigen.</p>'
                    . '<h2>Einstieg in Arbeit</h2><p>Eigene Erfahrungen erklären, eine Stellenanforderung verstehen und im Vorstellungsgespräch nachfragen: Berufliche Orientierung und Sprache gehören zusammen. Ein klares Ziel hilft, die passenden Übungsschwerpunkte zu finden.</p>'
                    . '<h2>Sicherer im Betrieb</h2><p>Arbeitsaufträge, Übergaben und Rückfragen brauchen klare Worte. Besonders in Gewerbe und Technik zählt, dass wichtige Informationen verstanden werden und Unklarheiten rechtzeitig angesprochen werden können.</p>'
                    . '<h2>Vor und während der Ausbildung</h2><p>Im Betrieb und in der Berufsschule werden unterschiedliche Texte und Gespräche gebraucht. Fachbegriffe und Lernstrategien üben wir passend zu Ihrem Berufsfeld.</p>',
                'facts' => ['topics_eyebrow' => 'Ihr Einstieg', 'topics_title' => 'Berufsdeutsch und Unternehmen'],
            ],
        ];
        $n = 0;
        foreach ($hubs as $slug => $h) {
            $row = $this->db->one('SELECT id, facts_json FROM services WHERE slug = ? AND parent_id IS NULL', [$slug]);
            if ($row === null) {
                continue;
            }
            $facts = is_string($row['facts_json']) ? (json_decode($row['facts_json'], true) ?: []) : [];
            $facts = array_merge($facts, $h['facts']);
            $set = ['teaser' => $h['teaser'], 'intro' => $h['intro'], 'facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
            if ($h['body'] !== null) {
                $set['body_html'] = $h['body'];
            }
            $this->update('services', (int) $row['id'], $set);
            $n++;
        }
        // Onboarding gehört zu den Informationsthemen von Recruiting, nicht zu den Coaching-Maßnahmen
        $onb = $this->db->one("SELECT s.id, s.facts_json FROM services s JOIN services p ON p.id = s.parent_id WHERE s.slug = 'onboarding' AND p.slug = 'recruiting'");
        if ($onb !== null) {
            $facts = is_string($onb['facts_json']) ? (json_decode($onb['facts_json'], true) ?: []) : [];
            $facts['info'] = true;
            $this->update('services', (int) $onb['id'], ['facts_json' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'sort_order' => 1]);
        }
        return "$n Bereichsseiten aktualisiert.";
    }

    // ---------------------------------------------------------------- Unterseiten

    private function subpages(): string
    {
        $defs = [
            'bildungszentrum' => [
                ['ausbildung', 'Ausbildungsvorbereitung', 'Ausbildung', 'Grundlagen für den Einstieg in eine Ausbildung', 'graduation-cap', 'bildungszentrum',
                    'Eine Ausbildung verbindet Lernen im Betrieb mit Unterricht in der Berufsschule. Wir besprechen Ihre Interessen und Erfahrungen, schauen auf die Anforderungen Ihres Berufsziels und planen die Vorbereitung bis zum Start.',
                    '<h2>Einen Berufswunsch genauer kennenlernen</h2><p>Ein Berufsname sagt wenig darüber, wie der Arbeitsalltag aussieht. Wir betrachten typische Aufgaben und Erwartungen, besonders in gewerblichen und technischen Berufsfeldern, und prüfen gemeinsam, ob der Wunsch zu Ihnen passt.</p>'
                    . '<h2>Interessen und Stärken</h2><p>Praktische Erfahrungen, schulische Kenntnisse und persönliche Interessen ordnen wir gemeinsam. So wird sichtbar, was Sie bereits mitbringen und welche Vorbereitung als Nächstes sinnvoll ist.</p>'
                    . '<h2>Lern- und Sprachgrundlagen</h2><p>Berufsschule und Betrieb verlangen unterschiedliche Texte und Gespräche. Wir schauen gezielt auf Lern- und Sprachgrundlagen und planen die nächsten Schritte in Richtung Ausbildung.</p>',
                    'Ausbildungsvorbereitung besprechen', 'Ausbildungsvorbereitung im Ruhrgebiet | MEA Bildungszentrum', 'Orientierung, Lern- und Sprachgrundlagen für den Einstieg in eine Ausbildung – persönliche Beratung beim MEA Bildungszentrum.'],
                ['sprachtraining', 'Berufsbezogenes Sprachtraining', 'Sprachtraining', 'Die Sprache lernen, die der Beruf verlangt', 'languages', 'bildungszentrum',
                    'Arbeitsaufträge verstehen, Rückfragen stellen, Fachbegriffe sicher anwenden: Das Sprachtraining orientiert sich an Ihren konkreten beruflichen Situationen.',
                    '<h2>Situationen statt Schulbuch</h2><p>Wir fragen, wo Sprache im Alltag Ihres Berufs wichtig ist: bei Arbeitsaufträgen, Übergaben, Gesprächen im Team oder in der Berufsschule. Daraus ergeben sich die Übungsschwerpunkte.</p>'
                    . '<h2>Alle vier Fertigkeiten</h2><p>Sprechen, Hören, Lesen und Schreiben werden gemeinsam betrachtet, damit Fortschritte im Arbeitsalltag ankommen.</p>'
                    . '<h2>Gruppe oder 1:1</h2><p>In Gruppen mit 10 bis 15 Personen üben Sie gemeinsam, in der Einzelbegleitung vertiefen Sie, was für Ihr Ziel wichtig ist.</p>',
                    'Sprachbedarf besprechen', 'Berufsbezogenes Sprachtraining | MEA Bildungszentrum', 'Sprachtraining mit Bezug zu Betrieb, Ausbildung und Berufsschule – in Gruppen oder im Einzelsetting.'],
                ['pruefungen', 'Prüfungen und Prüfungsvorbereitung', 'Prüfungen', 'Vorbereitung und Prüfungsangebote klären', 'award', 'bildungszentrum',
                    'MEA bietet telc-Prüfungen von A1 bis C1 und Prüfungsvorbereitung bis C2 an. Die Vorbereitung auf C2 ist keine C2-Prüfungsabnahme. Termine, Gebühren und Anmeldeweg stimmen wir persönlich mit Ihnen ab.',
                    '<h2>Prüfung oder Vorbereitung?</h2><p>Zuerst klären wir Ihr Ziel: Welches Zertifikat wird verlangt, und welches Niveau haben Sie heute? Daraus ergibt sich, ob eine Prüfung sofort sinnvoll ist oder zuerst eine Vorbereitung.</p>'
                    . '<h2>Zertifikat und Teilnahmebescheinigung</h2><p>Ein Prüfungszertifikat dokumentiert das Ergebnis einer bestimmten Prüfung; entscheidend sind Prüfungsname, Niveau und ausstellende Institution. Eine Teilnahmebescheinigung bestätigt dagegen den Besuch eines Kurses. Ob ein Nachweis anerkannt wird, klären Sie mit der Stelle, die ihn verlangt.</p>'
                    . '<h2>Prüfungen für Unternehmen</h2><p>Für Unternehmen sind Gruppenprüfungen möglich; die Einzelheiten stimmen wir persönlich ab. Eine Kursanmeldung ist nicht automatisch eine Prüfungsanmeldung.</p>'
                    . '<p><a href="/pruefungen/">Zu den telc-Prüfungen und Terminen in Herne</a></p>',
                    'Prüfungsart und Termin erfragen', 'telc-Prüfungen und Vorbereitung | MEA Bildungszentrum', 'telc-Prüfungen A1 bis C1 und Prüfungsvorbereitung bis C2 – Termine, Gebühren und Anmeldung persönlich klären.'],
            ],
            'recruiting' => [
                ['unternehmen', 'Recruiting für Unternehmen', 'Für Unternehmen', 'Personalbedarf und Zusammenarbeit abstimmen', 'briefcase', 'fachkraefte',
                    'Im Erstgespräch betrachten wir die betrieblichen Aufgaben, notwendige Qualifikationen und den tatsächlichen Sprachbedarf. So entsteht ein klares Profil, auf dem die Zusammenarbeit aufbaut.',
                    '<h2>Aufgaben statt Schlagworte</h2><p>Beschreiben Sie Aufgaben, Arbeitszeiten und Einarbeitung. Im Abgleich betrachten wir benötigte Erfahrung, Sprachkenntnisse sowie vorhandene Diplome und Qualifikationen – besonders für Gewerbe und Technik im Ruhrgebiet.</p>'
                    . '<h2>Entwicklung mitdenken</h2><p>Gute Voraussetzungen zeigen sich nicht nur im Lebenslauf. Lernbereitschaft, berufliche Interessen und die Möglichkeit zur Einarbeitung gehören ebenfalls in die Klärung zwischen Betrieb und interessierter Person.</p>'
                    . '<h2>Von der Qualifikation zum Arbeitsbeginn</h2><p>MEA prüft vorliegende Diplome und Qualifikationen und unterstützt bei der Planung von Zuzug und Arbeitsbeginn. Passende Kontakte werden mit beiden Seiten abgestimmt.</p>',
                    'Personalbedarf besprechen', 'Recruiting für Unternehmen im Ruhrgebiet | MEA', 'Personalbedarf klären, Qualifikationen prüfen, internationale Talente und regionale Betriebe verbinden – MEA Recruiting.'],
            ],
            'coaching' => [
                ['foerderung', 'Förderung, AVGS und Bildungsgutschein', 'Förderung & AZAV', 'Voraussetzungen und Förderwege verständlich erklärt', 'file-text', 'avgs',
                    'Welche Förderung für welches Angebot in Frage kommt, entscheidet die zuständige Stelle. Wir erläutern Voraussetzungen und Ablauf und prüfen mit Ihnen, ob Angebot und Gutschein zusammenpassen.',
                    '<h2>Ein Gutschein ist kein Freifahrtschein</h2><p>Ein Gutschein beschreibt einen bestimmten Förderzweck und kann zeitliche, regionale oder inhaltliche Grenzen enthalten. Bringen Sie ihn deshalb zur Beratung mit.</p>'
                    . '<h2>AVGS und Bildungsgutschein</h2><p>Der Aktivierungs- und Vermittlungsgutschein (AVGS) und der Bildungsgutschein sind unterschiedliche Förderwege. Beim Bildungsgutschein müssen Bildungsziel, zugelassener Träger und konkrete Maßnahme zusammenpassen. Er ist nicht einfach eine andere Bezeichnung für einen Coaching-Gutschein.</p>'
                    . '<h2>Zulassung bestätigen wir je Angebot</h2><p>Aktuelle Zulassung und Verfügbarkeit bestätigen wir für das konkrete Angebot. Über eine öffentliche Förderung entscheidet die zuständige Stelle; eine Zusage ergibt sich daraus nicht automatisch.</p>',
                    'Förderfrage stellen', 'Förderung, AVGS & Bildungsgutschein erklärt | MEA Coaching', 'AVGS, Bildungsgutschein und AZAV verständlich erklärt: Voraussetzungen, Ablauf und Zuständigkeiten.'],
                ['teilnehmende', 'Coaching für Teilnehmende', 'Für Teilnehmende', 'Ihr Anliegen und die nächsten Schritte', 'user-check', 'avgs',
                    'Erzählen Sie uns, wo Sie stehen. Wir klären im Gespräch, welches Coaching zu Ihrer Situation passt, und planen die nächsten Schritte gemeinsam mit Ihnen.',
                    '<h2>Ihr Anliegen als Ausgangspunkt</h2><p>Ob berufliche Orientierung, Kommunikation im Job oder die Sicherung einer Beschäftigung: Wir beginnen bei dem, was Sie beschäftigt.</p>'
                    . '<h2>So läuft das Erstgespräch</h2><p>Wir hören zu, ordnen Ziele und Voraussetzungen und besprechen, welches der drei Einzelangebote passen kann. Umfang und Dauer erklären wir verständlich.</p>'
                    . '<h2>Was Sie mitbringen können</h2><p>Wenn vorhanden, bringen Sie Ihren Gutschein oder Unterlagen der zuständigen Stelle mit. Notwendig ist das für das erste Gespräch nicht.</p>',
                    'Beratung anfragen', 'Coaching für Teilnehmende | MEA Coaching & Beratung', 'Persönliches 1:1-Coaching: Anliegen klären, passendes Angebot finden, nächste Schritte planen.'],
                ['jobcenter', 'Informationen für Jobcenter', 'Für Jobcenter', 'Konkrete Coachinginformationen anfragen', 'building', 'avgs',
                    'Angebotsumfang, Dauer und Zulassung der drei Einzelangebote auf einen Blick; Rückfragen beantworten wir direkt.',
                    '<h2>Die drei Angebote im Überblick</h2><ul><li>MEA Plus – Berufsbezogenes Kommunikationstraining: 120 UE, bis zu 3 Monate, 1:1</li><li>Stabilisierung von Beschäftigungsverhältnissen: 72 UE, bis zu 6 Monate, 1:1</li><li>Ganzheitliche Betreuung nach § 16k SGB II: 310 UE, bis zu 12 Monate, 1:1</li></ul>'
                    . '<h2>Zulassung und Verfügbarkeit</h2><p>Aktuelle Zulassung und Verfügbarkeit bestätigen wir für das konkrete Angebot auf Anfrage.</p>'
                    . '<h2>Zusammenarbeit</h2><p>Für Zuweisung, Rückmeldung und Fragen zu einzelnen Teilnehmenden stehen wir als feste Ansprechpartner zur Verfügung.</p>',
                    'Informationen anfragen', 'Coaching: Informationen für Jobcenter | MEA', 'Umfang, Dauer und Zulassung der MEA-Coachingangebote für Jobcenter und Partner.'],
            ],
            'sprache-beruf' => [
                ['berufsdeutsch', 'Berufsdeutsch', 'Berufsdeutsch', 'Arbeitsaufträge, Rückfragen und Fachsprache üben', 'message-circle', 'sprachkurs',
                    'Berufsbezogenes Deutsch lernen Sie an echten Situationen aus Ihrem Berufsfeld: Arbeitsaufträge verstehen, Rückfragen stellen und Fachbegriffe sicher verwenden.',
                    '<h2>Sprache für Ihre Aufgaben</h2><p>Wir üben, was im Arbeitsalltag gebraucht wird: Anweisungen verstehen, Übergaben klar formulieren und bei Unklarheiten nachfragen.</p>'
                    . '<h2>Gewerbe, Technik und Ausbildung</h2><p>Besonders in gewerblichen und technischen Berufen zählt, dass wichtige Informationen verstanden werden. Auch Berufsschule und Prüfungen bereiten wir sprachlich vor.</p>'
                    . '<h2>Lernform nach Ihrem Bedarf</h2><p>Ob Gruppe oder Einzelsetting: Ziel und Bedarf klären wir vorab im Gespräch.</p>',
                    'Sprachbedarf besprechen', 'Berufsdeutsch lernen im Ruhrgebiet | MEA Sprache & Beruf', 'Berufsbezogenes Deutsch für Betrieb, Ausbildung und Neuorientierung – praxisnah und am Bedarf orientiert.'],
                ['unternehmen', 'Sprachtraining für Unternehmen', 'Für Unternehmen', 'Sprachtraining für den betrieblichen Alltag', 'briefcase', 'sprachkurs',
                    'Wir richten Sprachtraining an Tätigkeiten und Abläufen Ihres Betriebs aus – mit konkreten Beispielen aus Ihrem Arbeitsalltag.',
                    '<h2>Bedarf klären</h2><p>Gemeinsam betrachten wir, in welchen Situationen Verständigung im Betrieb wichtig ist und wo es Rückfragen oder Missverständnisse gibt.</p>'
                    . '<h2>Training am Arbeitsalltag</h2><p>Inhalte entstehen aus Ihren Aufgaben, Abläufen und Fachbegriffen. So wird das Gelernte direkt angewendet.</p>'
                    . '<h2>Prüfungen für Mitarbeitende</h2><p>Für Unternehmen sind auch Gruppenprüfungen möglich; Möglichkeiten und Termine stimmen wir persönlich ab.</p>',
                    'Sprachtraining anfragen', 'Sprachtraining für Unternehmen | MEA Sprache & Beruf', 'Betriebsnahes Sprachtraining für Mitarbeitende – abgestimmt auf Aufgaben und Abläufe in Ihrem Unternehmen.'],
            ],
        ];
        $n = 0;
        foreach ($defs as $parentSlug => $items) {
            $p = $this->db->one('SELECT id, hero_media_id, card_media_id FROM services WHERE slug = ? AND parent_id IS NULL', [$parentSlug]);
            if ($p === null) {
                continue;
            }
            $sort = 10;
            foreach ($items as [$slug, $title, $menu, $teaser, $icon, $topic, $intro, $body, $cta, $seoTitle, $seoDesc]) {
                $sort++;
                if ($this->db->value('SELECT 1 FROM services WHERE parent_id = ? AND slug = ?', [$p['id'], $slug]) !== null) {
                    continue;
                }
                $this->db->insert('services', [
                    'parent_id' => $p['id'], 'slug' => $slug, 'title' => $title, 'menu_title' => $menu, 'icon' => $icon, 'teaser' => $teaser,
                    'intro' => $intro, 'body_html' => $body, 'facts_json' => json_encode(['info' => true], JSON_THROW_ON_ERROR),
                    'hero_media_id' => $p['hero_media_id'], 'card_media_id' => $p['card_media_id'],
                    'cta_label' => $cta, 'cta_url' => '/kontakt/?thema=' . $topic,
                    'seo_title' => mb_substr($seoTitle, 0, 70), 'seo_description' => mb_substr($seoDesc, 0, 170),
                    'status' => 'published', 'sort_order' => $sort,
                ]);
                $n++;
            }
        }
        return "$n Unterseiten angelegt.";
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
