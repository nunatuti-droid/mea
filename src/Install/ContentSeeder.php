<?php
declare(strict_types=1);

namespace Mea\Install;

use Mea\Core\Database;
use Mea\Module\Content\SettingsRepository;
use Mea\Module\Media\ImageVariantGenerator;

/**
 * Startdaten aus der bisherigen Website (Stand 29.10.2026), sprachlich überarbeitet.
 * Es werden keine Preise, Termine oder Bewertungen erfunden. Fehlende Angaben bleiben leer.
 */
final class ContentSeeder
{
    public const EXAM_REQUIREMENTS = '<p>Für die Teilnahme ist kein vorheriger Kurs erforderlich. Wir empfehlen eine gezielte Vorbereitung; sprechen Sie uns gerne an.</p>';

    /** @var array<string,int> */
    private array $m = [];

    public function __construct(private Database $db, private ImageVariantGenerator $images, private string $mediaDir) {}

    public function run(): void
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM settings') > 0) {
            throw new \RuntimeException('Startdaten sind bereits vorhanden.');
        }
        $this->db->transaction(function (): void {
            $this->media();
            $this->settings();
            $this->locations();
            $this->services();
            $this->pages();
            $this->exams();
            $this->faqs();
            $this->reviews();
            $this->menus();
            $this->redirects();
            (new EmailTemplateSeeder($this->db))->run();
        });
    }

    private function media(): void
    {
        $list = [
            'hero' => ['Onboarding.webp', 'Beraterin von MEA im Gespräch mit Fachkräften aus Pflege, Handwerk und Bau'],
            'exam_room' => ['MEA-Sprachkurse-und-telc-pruefungen.webp', 'Prüfungsraum mit Teilnehmenden bei einer telc-Prüfung im MEA Bildungszentrum'],
            'exam_writing' => ['telc-Pruefungen.webp', 'Teilnehmende schreiben eine telc-Sprachprüfung'],
            'exam_a2b1' => ['telc_Deustch_A2_B1-1024x1024.webp', 'Grafik zur Prüfung telc Deutsch A2-B1 mit den Teilen Lesen, Hören, Schreiben und Sprechen'],
            'services' => ['MEA-Leistungen.png', 'Zwei Beratende des MEA Bildungszentrums am Schreibtisch'],
            'coaching' => ['Coaching_MEA.png', 'Coachinggespräch am Laptop'],
            'avgs_plus' => ['MEA-Plus-Kommunikationstraining-1024x683.webp', 'MEA Plus – Berufsbezogenes Kommunikationstraining: 120 UE, 3 Monate, 1:1-Coaching'],
            'avgs_stab' => ['MEA-Stabilisierung-1024x683.webp', 'Stabilisierung von Beschäftigungsverhältnissen: 72 UE, 6 Monate, 1:1-Coaching'],
            'avgs_16k' => ['MEA-Ganzheitliche_Betreuung-1024x683.webp', 'Ganzheitliche Betreuung nach § 16k SGB II: 310 UE, bis zu 12 Monate, 1:1-Coaching'],
            'recruiter' => ['MEA-Recruiterin-1024x1024.webp', 'Recruiterin von MEA im Bewerbungsgespräch'],
            'onboarding' => ['MEA-Onboarding_hochformat.webp', 'Team von MEA mit neuen Fachkräften'],
            'language' => ['MEA-Sprachkurse-und-Kommunikationstraining.webp', 'Dozentin erklärt Kommunikation im Beruf an der Tafel'],
            'language_formats' => ['MEA-Sprachkurse-fuer-Alltag-und-Beruf-1024x1024.webp', 'Kursformate: Einzelunterricht, Gruppenkurs, Wochenendkurs, Online-Kurs und telc-Prüfungen A1 bis C1'],
            'lecturer_m' => ['MEA-Sprachdozent.webp', 'Sprachdozent unterrichtet eine Gruppe'],
            'lecturer_f' => ['MEA-Dozentin.jpg', 'Dozentin im Unterricht'],
            'vision' => ['MEA-Vision-1024x1024.webp', 'Illustration: ein Weg, der zu einem Ziel führt'],
            'logo_telc' => ['telc_pruefungszentrum.png', 'telc – Zertifiziertes Prüfungszentrum'],
            'logo_azav' => ['Certuria-Siegel-AZAV.png', 'Certuria – AZAV-Trägerzulassung'],
            'logo_ba' => ['Bundesagentur_fuer_Arbeit-Logo.svg-1024x213.png', 'Bundesagentur für Arbeit'],
            'logo_ihk' => ['ihk_small.jpg', 'IHK Nord Westfalen'],
            'logo_jc_herne' => ['32112_gross_transparent.png', 'Jobcenter Herne'],
            'logo_jc_re' => ['Bildschirmfoto_13-8-2024_141452_jobcenter-kreis-recklinghausen.de_.jpeg', 'Jobcenter Kreis Recklinghausen'],
            'logo' => ['mea_logo.png', 'MEA Bildungszentrum'],
            'logo_icon' => ['mea_logo_icon-150x150.png', 'MEA Bildungszentrum'],
        ];
        foreach ($list as $key => [$file, $alt]) {
            $this->m[$key] = $this->images->import($this->mediaDir . '/' . $file, $alt);
        }
    }

    private function settings(): void
    {
        $s = new SettingsRepository($this->db);
        $s->set('company', [
            'name' => 'MEA Bildungszentrum',
            'legal_name' => 'MEA Bildungszentrum GmbH',
            'claim' => 'Coaching. Qualifizierung. Vermittlung.',
            'email' => 'info@meabildungszentrum.de',
            'phone' => '+49 2365 6973491',
            'phone_display' => '02365 697 34 91',
            'whatsapp' => '+49 160 94700700',
            'whatsapp_display' => '0160 947 007 00',
            'facebook' => 'https://www.facebook.com/profile.php?id=61564242152187',
            'instagram' => 'https://www.instagram.com/bildungszentrum_mea/',
            'logo_media_id' => $this->m['logo'],
            'logo_icon_media_id' => $this->m['logo_icon'],
        ]);
        $s->set('home', [
            'eyebrow' => 'Bildungszentrum in Marl und Herne',
            'title' => 'MEA ist mehr: Fachkräfte. Zukunft. Erfolg.',
            'lead' => 'Wir begleiten Menschen und Unternehmen auf ihrem Weg: mit telc-Sprachprüfungen, Sprachkursen, Coaching über AVGS und der Gewinnung und Integration von Fachkräften.',
            'hero_media_id' => $this->m['hero'],
            'benefits' => [
                ['icon' => 'badge-check', 'title' => 'Lizenziertes telc-Prüfungszentrum', 'text' => 'Prüfungen nach dem Gemeinsamen Europäischen Referenzrahmen.'],
                ['icon' => 'shield-check', 'title' => 'AZAV-zugelassener Träger', 'text' => 'Coaching mit Aktivierungs- und Vermittlungsgutschein.'],
                ['icon' => 'map-pin', 'title' => 'Vor Ort im Ruhrgebiet', 'text' => 'Persönliche Beratung an unseren Standorten.'],
            ],
            'about_title' => 'Individuelle Bildungswege für Arbeitsuchende und Beschäftigte',
            'about_text' => 'Jeder Mensch bringt andere Voraussetzungen, Erfahrungen und Ziele mit. Deshalb planen wir Bildungs- und Karrierewege gemeinsam mit Ihnen: von der ersten Beratung über Sprachkurs und Prüfung bis zum neuen Arbeitsplatz.',
            'about_media_id' => $this->m['services'],
            'why' => [
                ['icon' => 'target', 'title' => 'Praxisnah', 'text' => 'Unsere Angebote richten sich nach dem, was Arbeitsmarkt und Betriebe wirklich brauchen.'],
                ['icon' => 'users', 'title' => 'Persönlich', 'text' => 'Einzelcoaching, kleine Gruppen und feste Ansprechpersonen statt Massenabfertigung.'],
                ['icon' => 'handshake', 'title' => 'Gut vernetzt', 'text' => 'Kontakte zu Arbeitgebern und Institutionen in Deutschland und in Südosteuropa.'],
                ['icon' => 'heart-handshake', 'title' => 'Respektvoll', 'text' => 'Ein Lernumfeld, das kulturelle und berufliche Vielfalt wertschätzt.'],
            ],
            'partners' => [
                ['media_id' => $this->m['logo_telc'], 'label' => 'telc Prüfungszentrum'],
                ['media_id' => $this->m['logo_azav'], 'label' => 'AZAV-Trägerzulassung (Certuria)'],
                ['media_id' => $this->m['logo_ba'], 'label' => 'Bundesagentur für Arbeit'],
                ['media_id' => $this->m['logo_ihk'], 'label' => 'IHK Nord Westfalen'],
                ['media_id' => $this->m['logo_jc_herne'], 'label' => 'Jobcenter Herne'],
                ['media_id' => $this->m['logo_jc_re'], 'label' => 'Jobcenter Kreis Recklinghausen'],
            ],
        ]);
        $s->set('seo_home', [
            'title' => 'MEA Bildungszentrum Marl & Herne – telc-Prüfungen, Sprachkurse, AVGS',
            'description' => 'telc-Sprachprüfungen, Deutschkurse, AVGS-Coaching und Fachkräftegewinnung in Marl und Herne. Prüfungstermine ansehen und online anmelden.',
        ]);
        $s->set('booking', ['steps' => [
            ['title' => 'Prüfung wählen', 'text' => 'Finden Sie die passende Prüfung und lesen Sie Ablauf und Voraussetzungen.'],
            ['title' => 'Termin auswählen', 'text' => 'Sehen Sie Datum, Ort, Preis und freie Plätze auf einen Blick.'],
            ['title' => 'Daten eingeben', 'text' => 'Füllen Sie das Anmeldeformular aus und bestätigen Sie die Bedingungen.'],
            ['title' => 'Online bezahlen', 'text' => 'Nach der Zahlung erhalten Sie Ihre Bestätigung per E-Mail.'],
        ]]);
    }

    private function locations(): void
    {
        $this->db->insert('locations', [
            'slug' => 'marl', 'name' => 'Standort Marl (Zentrale)', 'short_name' => 'Marl', 'is_headquarters' => 1,
            'street' => 'Barkhausstraße 22', 'postal_code' => '45768', 'city' => 'Marl',
            'phone' => '+49 2365 6973491', 'sort_order' => 1,
        ]);
        $this->db->insert('locations', [
            'slug' => 'herne', 'name' => 'Standort Herne', 'short_name' => 'Herne', 'is_headquarters' => 0, 'is_exam_center' => 1,
            'street' => 'Wilhelmstraße 98b', 'address_extra' => 'Gebäude 012', 'postal_code' => '44649', 'city' => 'Herne',
            // laut alter telc-Seite; Zuordnung zu Herne bitte von MEA bestätigen lassen
            'phone' => '+49 2325 578001', 'sort_order' => 2,
        ]);
    }

    private function service(array $row): int
    {
        $row += ['status' => 'published'];
        if (isset($row['facts_json']) && is_array($row['facts_json'])) {
            $row['facts_json'] = json_encode($row['facts_json'], JSON_UNESCAPED_UNICODE);
        }
        return $this->db->insert('services', $row);
    }

    private function services(): void
    {
        $this->service([
            'slug' => 'fachkraeftegewinnung', 'title' => 'Fachkräftegewinnung', 'menu_title' => 'Fachkräftegewinnung',
            'icon' => 'briefcase', 'sort_order' => 1,
            'teaser' => 'Qualifizierte Fachkräfte aus dem In- und Ausland – von der Bedarfsanalyse bis zum ersten Arbeitstag.',
            'intro' => 'Wir unterstützen Unternehmen dabei, qualifizierte Fachkräfte aus dem In- und Ausland zu gewinnen. Unser Schwerpunkt liegt auf der Rekrutierung in den Balkanstaaten.',
            'body_html' => '<h2>Für welche Branchen?</h2><p>Wir arbeiten unter anderem für Betriebe aus Industrie, Handwerk, Logistik und Pflege.</p>'
                . '<h2>Unsere Leistungen</h2><ul><li>Analyse Ihres Personalbedarfs</li><li>Rekrutierung geeigneter Kandidatinnen und Kandidaten</li><li>Vorauswahl und Bewerbermanagement</li><li>Unterstützung bei Anerkennungsverfahren</li><li>Begleitung bis zur Einstellung und Integration in Ihr Unternehmen</li></ul>',
            'facts_json' => ['process' => [
                ['title' => 'Bedarfsanalyse', 'text' => 'Wir klären mit Ihnen, welche Qualifikationen und Erfahrungen die neue Fachkraft mitbringen soll.'],
                ['title' => 'Erstgespräche', 'text' => 'Wir sprechen mit passenden Kandidatinnen und Kandidaten und prüfen Motivation und Erwartungen.'],
                ['title' => 'Qualifikationsprüfung', 'text' => 'Wir prüfen Abschlüsse und Nachweise und begleiten bei Bedarf die Anerkennung.'],
                ['title' => 'Umzugs- und Arbeitsstartplanung', 'text' => 'Wir planen gemeinsam die nötigen Vorbereitungen und den voraussichtlichen Arbeitsbeginn.'],
                ['title' => 'Erstkontakt herstellen', 'text' => 'Wir bringen Sie und Ihre zukünftige Fachkraft zusammen.'],
            ]],
            'hero_media_id' => $this->m['recruiter'], 'card_media_id' => $this->m['recruiter'],
            'cta_label' => 'Beratungstermin anfragen', 'cta_url' => '/kontakt/?thema=fachkraefte',
            'seo_title' => 'Fachkräftegewinnung aus dem Ausland – MEA Marl & Herne',
            'seo_description' => 'MEA unterstützt Unternehmen bei der Gewinnung von Fachkräften aus dem In- und Ausland – von der Bedarfsanalyse bis zur Einstellung.',
        ]);
        $this->service([
            'slug' => 'onboarding', 'title' => 'Onboarding für internationale Fachkräfte', 'menu_title' => 'Onboarding',
            'icon' => 'plane', 'sort_order' => 2,
            'teaser' => 'Ankommen leicht gemacht: Behördengänge, Wohnungssuche, Sprache und Familie aus einer Hand.',
            'intro' => 'Damit neue Fachkräfte schnell ankommen und bleiben, begleiten wir sie und ihre Familien durch die ersten Wochen in Deutschland.',
            'body_html' => '',
            'facts_json' => ['modules' => [
                ['icon' => 'house', 'title' => 'Wohnungssuche und Relocation', 'text' => 'Wohnungsrecherche, Besichtigungen, Umzugsplanung und Vermittlung von Relocation-Dienstleistern.'],
                ['icon' => 'file-text', 'title' => 'Behördengänge und Dokumente', 'text' => 'Hilfe bei Anmeldung, Steuer-ID, Krankenversicherung, Sozialversicherungsnummer, Bankkonto und Behördenterminen.'],
                ['icon' => 'languages', 'title' => 'Sprachkurse und Sprachtraining', 'text' => 'Allgemeine und berufsbezogene Deutschkurse sowie Vermittlung passender Kurse und Coachings.'],
                ['icon' => 'users', 'title' => 'Kulturelle Vorbereitung', 'text' => 'Einführung in Arbeitskultur und Umgangsformen sowie interkulturelle Trainings.'],
                ['icon' => 'heart-handshake', 'title' => 'Familienintegration', 'text' => 'Unterstützung bei Kita- und Schulplätzen, Orientierung und Vernetzung für Partnerinnen und Partner.'],
            ]],
            'hero_media_id' => $this->m['onboarding'], 'card_media_id' => $this->m['onboarding'],
            'cta_label' => 'Onboarding anfragen', 'cta_url' => '/kontakt/?thema=fachkraefte',
            'seo_title' => 'Onboarding für internationale Fachkräfte – MEA',
            'seo_description' => 'Behördengänge, Wohnungssuche, Sprachkurse und Familienintegration: MEA begleitet internationale Fachkräfte beim Ankommen in Deutschland.',
        ]);
        $this->service([
            'slug' => 'sprachkurse', 'title' => 'Sprachkurse und Kommunikationstraining', 'menu_title' => 'Sprachkurse',
            'icon' => 'languages', 'sort_order' => 3,
            'teaser' => 'Deutsch für Alltag und Beruf – im Einzeltraining, in der Gruppe, am Wochenende oder online.',
            'intro' => 'Deutschkenntnisse sind der Schlüssel zur beruflichen Integration. Ein B1-Zertifikat wird zum Beispiel häufig für Aufenthaltstitel, Einbürgerung und Ausbildung verlangt. Unsere Kurse verbinden Sprachaufbau mit Übungen aus Alltag und Beruf.',
            'body_html' => '',
            'facts_json' => ['modules' => [
                ['icon' => 'message-circle', 'title' => 'Berufsbezogenes Kommunikationscoaching', 'text' => 'Sicher und professionell sprechen mit Kolleginnen, Kunden und Vorgesetzten.'],
                ['icon' => 'briefcase', 'title' => 'Berufsbegleitendes Sprachcoaching', 'text' => 'Flexible Lernkonzepte, die sich an Ihren Arbeitsalltag anpassen.'],
                ['icon' => 'clipboard-check', 'title' => 'Vorbereitung auf Sprachprüfungen', 'text' => 'Gezielte Vorbereitung auf anerkannte Prüfungen der Niveaus A1 bis C1.'],
                ['icon' => 'calendar-days', 'title' => 'Wochenendkurse', 'text' => 'Unterricht am Wochenende für Berufstätige.'],
                ['icon' => 'search', 'title' => 'Online-Sprachkurse', 'text' => 'Ortsunabhängig lernen mit digitalen Methoden.'],
                ['icon' => 'award', 'title' => 'telc-Prüfungen', 'text' => 'Als lizenziertes telc-Prüfungszentrum nehmen wir Prüfungen der Niveaus A1 bis C1 ab.'],
            ]],
            'hero_media_id' => $this->m['language'], 'card_media_id' => $this->m['language_formats'],
            'cta_label' => 'Kursberatung anfragen', 'cta_url' => '/kontakt/?thema=sprachkurs',
            'seo_title' => 'Deutschkurse & Kommunikationstraining in Marl und Herne',
            'seo_description' => 'Deutschkurse für Alltag und Beruf: Einzel- und Gruppentraining, Wochenend- und Online-Kurse sowie Vorbereitung auf telc-Prüfungen A1 bis C1.',
        ]);
        $this->service([
            'slug' => 'telc-pruefungszentrum', 'title' => 'telc-Sprachprüfungen', 'menu_title' => 'telc-Prüfungen',
            'icon' => 'award', 'sort_order' => 4,
            'teaser' => 'Anerkannte Sprachzertifikate nach dem Gemeinsamen Europäischen Referenzrahmen – Termine online buchen.',
            'card_media_id' => $this->m['exam_writing'], 'cta_label' => 'Prüfungen ansehen', 'cta_url' => '/telc-pruefungszentrum/',
        ]);
        $avgs = $this->service([
            'slug' => 'avgs', 'title' => 'Coaching mit AVGS', 'menu_title' => 'Coaching (AVGS)',
            'icon' => 'user-check', 'sort_order' => 5,
            'teaser' => 'Individuelles 1:1-Coaching mit Aktivierungs- und Vermittlungsgutschein für Ihren Weg in Arbeit.',
            'intro' => 'Mit einem Aktivierungs- und Vermittlungsgutschein (AVGS) unterstützen wir Arbeitsuchende im Einzelcoaching: bei der beruflichen Orientierung, den Bewerbungsunterlagen, der Vorbereitung auf Vorstellungsgespräche und der Suche nach Praktikum oder Arbeitsplatz.',
            'body_html' => '<p>Unsere Standorte in Marl und Herne (Wanne) sind für Maßnahmen nach § 45 Abs. 1 Satz 1 Nr. 1–5 SGB III zugelassen.</p>',
            'hero_media_id' => $this->m['coaching'], 'card_media_id' => $this->m['coaching'],
            'cta_label' => 'Beratungstermin anfragen', 'cta_url' => '/kontakt/?thema=avgs',
            'seo_title' => 'AVGS-Coaching in Marl und Herne – MEA Bildungszentrum',
            'seo_description' => 'Individuelles 1:1-Coaching mit Aktivierungs- und Vermittlungsgutschein (AVGS): MEA Plus, Job-Mentoring und Betreuung nach § 16k SGB II.',
        ]);
        $children = [
            ['mea-plus', 'MEA Plus – Berufsbezogenes Kommunikationstraining', 'avgs_plus',
                'Für Teilnehmende mit schwerwiegenden Vermittlungshemmnissen, auf Wunsch unter Einbezug der Bedarfsgemeinschaft.',
                [['Betreuungsumfang', '120 UE'], ['Dauer', '3 Monate'], ['Coachingform', '1:1-Coaching']]],
            ['stabilisierung', 'Stabilisierung von Beschäftigungsverhältnissen', 'avgs_stab',
                'Job-Mentoring: Wir begleiten Beschäftigte in der Probezeit und unterstützen bei einem Jobwechsel.',
                [['Betreuungsumfang', '72 UE'], ['Dauer', '6 Monate'], ['Coachingform', '1:1-Coaching']]],
            ['ganzheitliche-betreuung', 'Ganzheitliche Betreuung nach § 16k SGB II', 'avgs_16k',
                'Für erwerbsfähige Leistungsberechtigte mit komplexen Vermittlungshemmnissen.',
                [['Betreuungsumfang', '310 UE'], ['Dauer', 'bis zu 12 Monate'], ['Coachingform', '1:1-Coaching']]],
        ];
        $short = ['mea-plus' => 'MEA Plus', 'stabilisierung' => 'Job-Mentoring', 'ganzheitliche-betreuung' => 'Betreuung nach § 16k SGB II'];
        foreach ($children as $i => [$slug, $title, $img, $teaser, $facts]) {
            $this->service([
                'parent_id' => $avgs, 'slug' => $slug, 'title' => $title, 'menu_title' => $short[$slug], 'icon' => 'user-check', 'sort_order' => $i + 1,
                'teaser' => $teaser, 'intro' => $teaser,
                'body_html' => '<p>' . htmlspecialchars($teaser) . '</p><p>Die Maßnahme kann mit einem Aktivierungs- und Vermittlungsgutschein (AVGS) gefördert werden. Ob Sie einen Gutschein erhalten, klären Sie mit Ihrer Ansprechperson bei der Agentur für Arbeit oder dem Jobcenter. Wir beraten Sie gerne vorab.</p>',
                'facts_json' => ['facts' => array_map(fn($f) => ['label' => $f[0], 'value' => $f[1]], $facts)],
                'hero_media_id' => $this->m[$img], 'card_media_id' => $this->m[$img],
                'cta_label' => 'Beratungstermin anfragen', 'cta_url' => '/kontakt/?thema=avgs',
                'seo_title' => mb_substr($title . ' – AVGS', 0, 70),
                'seo_description' => mb_substr($teaser . ' ' . implode(', ', array_map(fn($f) => $f[1], $facts)) . '.', 0, 170),
            ]);
        }
        $this->service([
            'slug' => 'beschaeftigten-qualifizierung', 'title' => 'Beschäftigtenqualifizierung', 'menu_title' => 'Qualifizierung',
            'icon' => 'graduation-cap', 'sort_order' => 6,
            'teaser' => 'Weiterbildung für Beschäftigte, die sich am tatsächlichen Bedarf von Arbeitsmarkt und Betrieb orientiert.',
            'intro' => 'Wir qualifizieren Beschäftigte praxisnah und begleiten Unternehmen bei der Auswahl und Vorbereitung neuer Mitarbeitender.',
            'body_html' => '',
            'facts_json' => ['modules' => [
                ['icon' => 'badge-check', 'title' => 'Qualität', 'text' => 'Hohe Ansprüche für nachhaltige Lernergebnisse: praxisnahe Methoden, erfahrene Lehrkräfte, individuelle Begleitung.'],
                ['icon' => 'target', 'title' => 'Praxisnähe und Effizienz', 'text' => 'Inhalte, die sich am Bedarf des Arbeitsmarkts orientieren und im Job direkt anwendbar sind.'],
                ['icon' => 'users', 'title' => 'Respekt und Vielfalt', 'text' => 'Ein inklusives Lernumfeld, das kulturelle und berufliche Unterschiede wertschätzt.'],
                ['icon' => 'heart-handshake', 'title' => 'Engagement', 'text' => 'Persönliche Begleitung, Prüfungsvorbereitung und Hilfe bei beruflichen Herausforderungen.'],
                ['icon' => 'sparkles', 'title' => 'Nachhaltigkeit', 'text' => 'Lebenslanges Lernen und ein bewusster Umgang mit Ressourcen.'],
                ['icon' => 'handshake', 'title' => 'Starkes Netzwerk', 'text' => 'Verbindungen zu Arbeitgebern und Institutionen in Deutschland und in Südosteuropa.'],
                ['icon' => 'clipboard-check', 'title' => 'Vorgespräche', 'text' => 'Auswahlverfahren und Vorgespräche, die Unternehmen bei der Personalauswahl helfen.'],
            ]],
            'hero_media_id' => $this->m['lecturer_f'], 'card_media_id' => $this->m['lecturer_f'],
            'cta_label' => 'Beratung anfragen', 'cta_url' => '/kontakt/',
            'seo_title' => 'Beschäftigtenqualifizierung – MEA Bildungszentrum',
            'seo_description' => 'Praxisnahe Qualifizierung für Beschäftigte und Unterstützung bei der Personalauswahl – MEA Bildungszentrum in Marl und Herne.',
        ]);
    }

    private function page(string $slug, string $title, string $body, array $extra = []): void
    {
        $this->db->insert('pages', $extra + [
            'slug' => $slug, 'title' => $title, 'body_html' => $body, 'status' => 'published',
            'published_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function pages(): void
    {
        $this->page('leistungen', 'Unsere Leistungen', '', [
            'intro' => 'Individuelle Lösungen für Bildung, Integration und Fachkräftegewinnung – für Menschen, die sich beruflich weiterentwickeln wollen, und für Unternehmen, die Fachkräfte suchen.',
            'hero_media_id' => $this->m['services'], 'is_system' => 1,
            'seo_title' => 'Leistungen – MEA Bildungszentrum Marl & Herne',
            'seo_description' => 'Fachkräftegewinnung, Onboarding, Sprachkurse, telc-Prüfungen, AVGS-Coaching und Qualifizierung: alle Leistungen des MEA Bildungszentrums.',
        ]);
        $this->page('telc-pruefungszentrum', 'telc-Prüfungszentrum', 
            '<h2>Intensive Auffrischungskurse und gezielte Prüfungsvorbereitung</h2><p>Für alle Stufen des Gemeinsamen Europäischen Referenzrahmens (A1 bis C2) bieten wir Vorbereitungskurse an, zum Beispiel einen 6-wöchigen Intensivkurs oder einen 2-wöchigen Auffrischungskurs.</p>'
            . '<h2>Für Unternehmen: Sprachprüfungen gezielt einsetzen</h2><p>Sie möchten die Sprachkenntnisse Ihrer Mitarbeitenden nachweisen lassen? Sprechen Sie uns an – wir beraten Sie zur passenden Prüfung.</p>', [
            'intro' => 'Zertifiziert. Anerkannt. Zukunftsorientiert. Als lizenziertes telc-Prüfungszentrum beraten wir Sie zur passenden Prüfung und führen sie professionell und transparent durch.',
            'hero_media_id' => $this->m['exam_room'], 'is_system' => 1,
            'seo_title' => 'telc-Prüfungen in Herne – Termine und Anmeldung',
            'seo_description' => 'Lizenziertes telc-Prüfungszentrum: Deutschprüfungen nach GER, aktuelle Termine, Preise und freie Plätze. Jetzt online anmelden.',
        ]);
        $this->page('preise', 'Preise', '', [
            'intro' => 'Alle Prüfungspreise auf einen Blick. Der Preis eines einzelnen Termins kann abweichen; maßgeblich ist der Preis, der bei der Anmeldung angezeigt wird.',
            'is_system' => 1,
            'seo_title' => 'Preise für telc-Prüfungen – MEA Bildungszentrum',
            'seo_description' => 'Übersicht der Prüfungsgebühren für telc-Sprachprüfungen im zertifizierten telc-Prüfungszentrum des MEA Bildungszentrums in Herne.',
        ]);
        $this->page('standorte', 'Unsere Standorte', '', [
            'intro' => 'Sie finden uns an mehreren Standorten im Ruhrgebiet. Wählen Sie einen Standort für Adresse, Anfahrt und Kontakt.',
            'is_system' => 1,
            'seo_title' => 'Standorte – MEA Bildungszentrum Marl & Herne',
            'seo_description' => 'Adressen, Anfahrt und Kontakt der Standorte des MEA Bildungszentrums in Marl und Herne.',
        ]);
        $this->page('kontakt', 'Kontakt', '', [
            'intro' => 'Sie haben eine Frage zu einer Prüfung, einem Kurs oder einem Coaching? Schreiben Sie uns oder rufen Sie an – wir melden uns schnellstmöglich zurück.',
            'is_system' => 1,
            'seo_title' => 'Kontakt – MEA Bildungszentrum',
            'seo_description' => 'Kontakt zum MEA Bildungszentrum: Telefon, WhatsApp und Kontaktformular für Fragen zu Prüfungen, Kursen und Coaching.',
        ]);
        $this->page('faq', 'Häufige Fragen', '', [
            'intro' => 'Antworten auf die wichtigsten Fragen zu Prüfungen, Anmeldung und Coaching.',
            'is_system' => 1,
            'seo_title' => 'Häufige Fragen – MEA Bildungszentrum',
            'seo_description' => 'Antworten auf häufige Fragen zu telc-Prüfungen, Anmeldung, Bezahlung und AVGS-Coaching beim MEA Bildungszentrum.',
        ]);
        $this->page('ueber-uns', 'Über uns', 
            '<h2>Unsere Vision</h2><p>Wir wollen ein Anbieter hochwertiger Erwachsenenbildung sein. Dahinter steht die Überzeugung, dass Bildung der Schlüssel zu persönlichem und beruflichem Erfolg ist.</p>'
            . '<h2>Unsere Mission</h2><p>Wir unterstützen die persönliche und berufliche Entwicklung unserer Teilnehmenden mit wirksamen Angeboten. Drei Grundsätze leiten uns: lebenslanges Lernen, Befähigung und Weiterkommen durch praxisnahe Lernmethoden.</p>'
            . '<h2>Unsere Werte</h2><ul><li><strong>Qualität</strong> – hohe Ansprüche an Unterricht und Begleitung</li><li><strong>Innovation</strong> – neue Methoden, wo sie Lernenden helfen</li><li><strong>Respekt</strong> – ein inklusives Lernumfeld</li><li><strong>Engagement</strong> – persönliche Unterstützung</li><li><strong>Nachhaltigkeit</strong> – Lernen, das bleibt</li></ul>'
            . '<h2>Unser Team</h2><p>Unsere Dozentinnen, Dozenten und Coaches verbinden Fachwissen mit Praxiserfahrung und schaffen ein unterstützendes Lernumfeld.</p>', [
            'intro' => 'Bildung ist der Schlüssel zu persönlichem und beruflichem Erfolg. Dafür arbeiten wir jeden Tag.',
            'hero_media_id' => $this->m['vision'], 'template' => 'about',
            'seo_title' => 'Über uns – Leitbild des MEA Bildungszentrums',
            'seo_description' => 'Vision, Mission und Werte des MEA Bildungszentrums: hochwertige Erwachsenenbildung, praxisnah und respektvoll.',
        ]);
        $this->page('karriere', 'Karriere bei MEA',
            '<h2>Jobcoach / Dozent:in (Freelancer)</h2><p>Sie beraten Arbeitsuchende und Langzeitarbeitslose im Einzel- und Gruppensetting, erkennen gemeinsam Stärken und vermitteln in Praktika und sozialversicherungspflichtige Beschäftigung.</p>'
            . '<h3>Das bringen Sie mit</h3><ul><li>Abgeschlossenes Studium oder Ausbildung, idealerweise im Personal- oder Bildungsbereich (AEVO wünschenswert, aber keine Voraussetzung)</li><li>Erfahrung in Berufsberatung oder Arbeitsmarktintegration</li><li>Teamfähigkeit, Empathie und Kommunikationsstärke</li><li>Gute Deutschkenntnisse, weitere Sprachen von Vorteil</li></ul>'
            . '<h3>Das bieten wir</h3><ul><li>Abwechslungsreiche Tätigkeit in einem interdisziplinären Team</li><li>Kurze Entscheidungswege und Weiterbildungsmöglichkeiten</li><li>Gute Verkehrsanbindung</li><li>Honorar nach branchenüblichen Sätzen für AVGS-Maßnahmen</li></ul>'
            . '<h2>Sprachdozent:in (Freelancer)</h2><p>Sie unterstützen Teilnehmende dabei, ihre Sprachkenntnisse zu verbessern und sicher zu kommunizieren.</p>'
            . '<h3>Das bringen Sie mit</h3><ul><li>Studium oder Ausbildung in Sprachwissenschaft, Pädagogik oder einem verwandten Bereich</li><li>Erfahrung in Sprachtraining oder Coaching</li><li>Empathie, Geduld und eine klare Vermittlung</li><li>Gute Deutschkenntnisse, idealerweise weitere Sprachen</li></ul>'
            . '<h3>Das bieten wir</h3><ul><li>Ein inspirierendes Umfeld und ein unterstützendes Team</li><li>Flexible Arbeitszeiten</li><li>Raum für eigene Unterrichtskonzepte</li></ul>'
            . '<h2>Bewerbung</h2><p>Ihre Ansprechpartner sind Frau Mernica und Herr Cizmic. Schicken Sie uns Ihre Unterlagen über das <a href="/kontakt/?thema=sonstiges">Kontaktformular</a> oder per E-Mail.</p>', [
            'intro' => 'Wir suchen Menschen, die andere auf ihrem beruflichen Weg begleiten wollen.',
            'hero_media_id' => $this->m['lecturer_m'],
            'seo_title' => 'Karriere: Jobcoach und Sprachdozent:in – MEA',
            'seo_description' => 'Freie Stellen beim MEA Bildungszentrum: Jobcoach / Dozent:in und Sprachdozent:in auf Honorarbasis in Marl und Herne.',
        ]);
        $this->page('impressum', 'Impressum',
            '<h2>Angaben gemäß § 5 DDG</h2><p>MEA Bildungszentrum GmbH<br>Barkhausstraße 22<br>45768 Marl</p>'
            . '<h2>Vertreten durch</h2><p>Elmedina Mernica und Saudin Cizmic (Geschäftsführung)</p>'
            . '<h2>Registereintrag</h2><p>Amtsgericht Recklinghausen, HRB 17674</p>'
            . '<h2>Kontakt</h2><p>Telefon: +49 2365 6973491<br>E-Mail: über unser <a href="/kontakt/">Kontaktformular</a></p>'
            . '<h2>Umsatzsteuer-ID</h2><p><em>Angabe folgt.</em></p>'
            . '<h2>Verantwortlich für den Inhalt</h2><p>Saudin Cizmic und Elmedina Mernica, Barkhausstraße 22, 45768 Marl</p>', [
            'template' => 'legal', 'is_system' => 1,
            'seo_title' => 'Impressum – MEA Bildungszentrum GmbH',
            'seo_description' => 'Impressum der MEA Bildungszentrum GmbH, Barkhausstraße 22, 45768 Marl.',
        ]);
        $this->page('datenschutz', 'Datenschutzerklärung',
            '<p><strong>Hinweis:</strong> Die Datenschutzerklärung für die neue Website wird derzeit fachlich erstellt und geprüft. Bis dahin gilt die Datenschutzerklärung der bisherigen Website.</p>'
            . '<h2>Verantwortlicher</h2><p>MEA Bildungszentrum GmbH, Barkhausstraße 22, 45768 Marl. Vertreten durch Elmedina Mernica und Saudin Cizmic.</p>'
            . '<h2>Was diese Website technisch tut</h2><ul><li>Keine Tracking- oder Marketing-Cookies, keine Analysedienste.</li><li>Schriften und Bilder werden vom eigenen Server geladen.</li><li>Kartendienste (OpenStreetMap) werden erst nach Ihrem Klick geladen.</li><li>Ein technisch notwendiges Sitzungs-Cookie wird nur beim Absenden von Formularen gesetzt.</li></ul>', [
            'template' => 'legal', 'is_system' => 1,
            'seo_title' => 'Datenschutzerklärung – MEA Bildungszentrum',
            'seo_description' => 'Informationen zum Datenschutz auf der Website des MEA Bildungszentrums.',
        ]);
        $this->page('agb', 'Allgemeine Geschäftsbedingungen',
            '<p><strong>Hinweis:</strong> Die Geschäfts-, Teilnahme- und Stornobedingungen für Online-Buchungen werden derzeit fachlich erstellt. Sie werden hier veröffentlicht, bevor die Online-Buchung startet.</p>', [
            'template' => 'legal', 'is_system' => 1,
            'seo_title' => 'AGB – MEA Bildungszentrum',
            'seo_description' => 'Allgemeine Geschäftsbedingungen und Teilnahmebedingungen des MEA Bildungszentrums.',
        ]);
    }

    private function exams(): void
    {
        $provider = $this->db->insert('exam_providers', ['name' => 'telc', 'website' => 'https://www.telc.net']);
        $rule = $this->db->insert('booking_rules', [
            'name' => 'Standard telc-Prüfung', 'registration_days_before' => 14, 'reservation_minutes' => 20,
            'refund_allowed' => 1, 'is_default' => 1,
            'required_fields_json' => json_encode(['first_name', 'last_name', 'birth_date', 'birth_place', 'email', 'phone', 'address']),
        ]);
        $general = $this->db->value("SELECT id FROM exam_categories WHERE slug = 'allgemeinsprachlich'");
        $i = 0;
        foreach (TelcGermanExams::all() as $slug => $e) {
            $lvl = $e['level_label'];
            $img = $slug === 'telc-deutsch-a2-b1' ? 'exam_a2b1' : ($i % 2 === 0 ? 'exam_writing' : 'exam_room');
            $this->db->insert('exam_types', $e + [
                'provider_id' => $provider, 'slug' => $slug, 'category' => 'Deutsch', 'category_id' => $general,
                'requirements_html' => self::EXAM_REQUIREMENTS,
                'booking_rule_id' => $rule,
                'card_media_id' => $this->m[$img], 'hero_media_id' => $this->m[$slug === 'telc-deutsch-a2-b1' ? 'exam_writing' : $img],
                'seo_title' => mb_substr($e['public_name'] . ' Prüfung in Herne', 0, 70),
                'seo_description' => mb_substr($e['public_name'] . ' beim MEA Bildungszentrum im telc-Prüfungszentrum Herne: Ablauf, Termine, Preise und Online-Anmeldung.', 0, 170),
                'status' => 'draft',
            ]);
            $i++;
        }
    }

    private function faq(string $q, string $a, array $targets, string $cat = 'Prüfungen'): void
    {
        static $sort = 0;
        $id = $this->db->insert('faqs', ['question' => $q, 'answer_html' => $a, 'category' => $cat, 'sort_order' => ++$sort * 10]);
        foreach ($targets as $t) {
            $this->db->insert('faq_assignments', ['faq_id' => $id, 'target_type' => $t]);
        }
    }

    private function faqs(): void
    {
        $this->faq('Wie melde ich mich zu einer Prüfung an?',
            '<p>Wählen Sie die passende Prüfung und einen freien Termin. Danach geben Sie Ihre Daten ein, bestätigen die Teilnahmebedingungen und bezahlen online. Ihre Anmeldung ist verbindlich, sobald die Zahlung bestätigt ist.</p>', ['home', 'faq_page']);
        $this->faq('Wann erhalte ich meine Anmeldebestätigung?',
            '<p>Sobald Ihre Zahlung bei uns eingegangen und geprüft ist, erhalten Sie eine Bestätigung per E-Mail mit allen Angaben zu Termin und Ort.</p>', ['home', 'faq_page']);
        $this->faq('Was mache ich, wenn ein Termin ausgebucht ist?',
            '<p>Wählen Sie einen anderen Termin derselben Prüfung oder schreiben Sie uns über das Kontaktformular. Wir informieren Sie gerne über neue Termine.</p>', ['faq_page']);
        $this->faq('Aus welchen Teilen besteht eine telc-Deutschprüfung?',
            '<p>Die telc-Deutschprüfungen prüfen Lesen, Hören, Schreiben und Sprechen. Den genauen Ablauf finden Sie auf der Seite der jeweiligen Prüfung.</p>', ['home', 'faq_page']);
        $this->faq('Bieten Sie Vorbereitungskurse an?',
            '<p>Ja. Für alle Stufen von A1 bis C2 bieten wir Vorbereitungskurse an, zum Beispiel einen 6-wöchigen Intensivkurs oder einen 2-wöchigen Auffrischungskurs. Sprechen Sie uns für Termine an.</p>', ['home', 'faq_page'], 'Kurse');
        $this->faq('Was ist ein Aktivierungs- und Vermittlungsgutschein (AVGS)?',
            '<p>Mit einem AVGS fördern die Agentur für Arbeit oder das Jobcenter Maßnahmen, die Sie bei der beruflichen Eingliederung unterstützen, zum Beispiel ein Einzelcoaching. Ob Sie einen Gutschein erhalten, entscheidet Ihre Ansprechperson dort. Wir beraten Sie gerne zu unseren Maßnahmen.</p>', ['home', 'faq_page'], 'Coaching');
    }

    private function reviews(): void
    {
        $note = 'Von der bisherigen Website übernommen (gekürzt), Stand 10/2026';
        $rows = [
            ['Michael D.', 'Fachlagerist', 'Eine großartige Erfahrung! Die individuelle Beratung und die Betreuung während meiner Ausbildung waren herausragend.'],
            ['Adis S.', 'Kranführer', 'Das MEA Bildungszentrum hat mir geholfen, meine Fähigkeiten zu entwickeln und mich beruflich weiterzuentwickeln.'],
            ['Linda R.', 'Pflegeassistentin', 'Ich bin sehr zufrieden mit dem Betrieb, in dem mich das MEA Bildungszentrum erfolgreich vermittelt hat.'],
        ];
        foreach ($rows as $i => [$n, $r, $t]) {
            $this->db->insert('reviews', [
                'display_name' => $n, 'role_label' => $r, 'body' => $t, 'source_note' => $note,
                'show_in_hero' => $i === 0 ? 1 : 0, 'sort_order' => $i + 1,
            ]);
        }
    }

    private function menus(): void
    {
        $main = $this->db->insert('menus', ['menu_key' => 'main', 'name' => 'Hauptmenü']);
        $add = fn(int $menu, string $label, string $url, int $sort, ?int $parent = null, ?string $desc = null): int
            => $this->db->insert('menu_items', ['menu_id' => $menu, 'parent_id' => $parent, 'label' => $label, 'url' => $url, 'sort_order' => $sort, 'description' => $desc]);
        $l = $add($main, 'Leistungen', '/leistungen/', 1);
        $add($main, 'Fachkräftegewinnung', '/fachkraeftegewinnung/', 1, $l, 'Fachkräfte aus dem In- und Ausland');
        $add($main, 'Onboarding', '/onboarding/', 2, $l, 'Ankommen in Deutschland');
        $add($main, 'Sprachkurse', '/sprachkurse/', 3, $l, 'Deutsch für Alltag und Beruf');
        $add($main, 'Coaching (AVGS)', '/avgs/', 4, $l, '1:1-Coaching mit Gutschein');
        $add($main, 'Qualifizierung', '/beschaeftigten-qualifizierung/', 5, $l, 'Weiterbildung für Beschäftigte');
        $p = $add($main, 'Prüfungen', '/telc-pruefungszentrum/', 2);
        $add($main, 'Alle Prüfungen und Termine', '/telc-pruefungszentrum/', 1, $p, 'telc-Prüfungen online buchen');
        $add($main, 'Preise', '/preise/', 2, $p, 'Prüfungsgebühren im Überblick');
        $add($main, 'Häufige Fragen', '/faq/', 3, $p, 'Anmeldung, Ablauf, Bezahlung');
        $add($main, 'Standorte', '/standorte/', 3);
        $add($main, 'Über uns', '/ueber-uns/', 4);
        $add($main, 'Kontakt', '/kontakt/', 5);

        $footer = $this->db->insert('menus', ['menu_key' => 'footer', 'name' => 'Footer']);
        foreach ([['Über uns', '/ueber-uns/'], ['Karriere', '/karriere/'], ['Standorte', '/standorte/'], ['Preise', '/preise/'], ['Häufige Fragen', '/faq/'], ['Kontakt', '/kontakt/']] as $i => [$a, $b]) {
            $add($footer, $a, $b, $i + 1);
        }
        $legal = $this->db->insert('menus', ['menu_key' => 'legal', 'name' => 'Rechtliches']);
        foreach ([['Impressum', '/impressum/'], ['Datenschutz', '/datenschutz/'], ['AGB', '/agb/']] as $i => [$a, $b]) {
            $add($legal, $a, $b, $i + 1);
        }
    }

    private function redirects(): void
    {
        $map = [
            '/unser-leitbild/' => '/ueber-uns/', '/karriere-dozenten/' => '/karriere/', '/hier-herunterladen/' => '/avgs/',
            '/product/telc-sprachpruefung-deutsch-a2-b1/' => '/pruefungen/telc-deutsch-a2-b1/',
            '/cookie-richtlinie-eu/' => '/datenschutz/', '/telc/' => '/telc-pruefungszentrum/',
            '/weiterbildungen/' => '/leistungen/', '/bewerberprofile/' => '/fachkraeftegewinnung/',
            '/courses/' => '/telc-pruefungszentrum/', '/shop-2/' => '/telc-pruefungszentrum/', '/cart/' => '/telc-pruefungszentrum/',
            '/checkout/' => '/telc-pruefungszentrum/', '/my-account/' => '/', '/product-category/a2-b1/' => '/telc-pruefungszentrum/',
            '/product-category/booking/' => '/telc-pruefungszentrum/', '/faqs/' => '/faq/', '/contact-me/' => '/kontakt/',
            '/privacy-policy-2/' => '/datenschutz/', '/terms-condition/' => '/agb/',
            '/courses/starting-seo-as-your-home-based-business/' => '/kontakt/',
        ];
        foreach ($map as $from => $to) {
            $this->db->insert('redirects', ['source_path' => $from, 'target_url' => $to, 'status_code' => 301]);
        }
        $gone = ['/sample-page/', '/lp-checkout/', '/lp-checkout-2/', '/lp-profile/', '/lp-profile-2/', '/instructors/', '/instructors-2/',
            '/instructor/', '/instructor-1/', '/instructor-2-2/', '/instructor-3/', '/instructor-4/', '/lp-become-a-teacher/',
            '/lp-become-a-teacher-2/', '/lp-term-conditions/', '/lp-term-conditions-2/', '/user-register/', '/user-register-2/',
            '/user-login/', '/user-login-2/', '/forgot-password/', '/forgot-password-2/', '/reset-password/', '/reset-password-2/',
            '/user-account/', '/user-account-2/', '/component/', '/component/footer/', '/component/brand/', '/component/cta/',
            '/component/counterup/', '/component/accordion/', '/component/video-popup/', '/component/features/',
            '/component/why-choose/', '/component/services/', '/component/other-sections/', '/coming-soon/', '/purchase-guide/',
            '/pricing-table/', '/gallery-grid/', '/gallery-masonry/', '/cart-2/', '/checkout-2/', '/blog/', '/event-style-1/',
            '/courses-2/', '/my-account-2/', '/elementor-18013/', '/course-category/business/', '/course-category/motivation/',
            '/course-category/programming/', '/course-category/online-art/', '/course-category/fitness/',
            '/course-category/digital-marketing/', '/course-category/photography/', '/course-tag/development/',
            '/courses/grow-personal-financial-security-thinking-principles-2/'];
        foreach ($gone as $g) {
            $this->db->insert('redirects', ['source_path' => $g, 'target_url' => null, 'status_code' => 410]);
        }
    }

    /** @return array<string,int> */
    public function mediaIds(): array
    {
        return $this->m;
    }
}
