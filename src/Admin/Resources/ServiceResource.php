<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Admin\Labels;
use Mea\Core\Database;

/** Leistungen (z. B. Fachkräftegewinnung, AVGS-Maßnahmen) mit Bausteinen, Ablauf und Eckdaten. */
final class ServiceResource extends Resource
{
    public string $key = 'leistungen';
    public string $table = 'services';
    public string $permission = 'content';
    public string $singular = 'Leistung';
    public string $plural = 'Leistungen';
    public string $entity = 'service';
    public string $orderBy = 'sort_order, id';
    public bool $tree = true;
    public array $searchColumns = ['title', 'slug'];
    public ?string $revisionEntity = 'service';

    public const ICONS = ['briefcase', 'users', 'languages', 'award', 'graduation-cap', 'user-check', 'handshake', 'house', 'file-text', 'shield-check', 'target',
        'heart-handshake', 'book-open', 'plane', 'clipboard-check', 'message-circle', 'map', 'sparkles', 'calendar', 'building-2', 'route', 'euro', 'clock', 'star'];

    /** @var \Closure(array<string,mixed>):string */
    private \Closure $previewer;

    public function __construct(Database $db, \Closure $previewer)
    {
        parent::__construct($db);
        $this->previewer = $previewer;
    }

    public function baseQuery(): string
    {
        return 'SELECT s.*, p.title AS parent_title, COALESCE(p.sort_order, s.sort_order) AS parent_sort FROM services s LEFT JOIN services p ON p.id = s.parent_id';
    }

    /** Hauptleistungen (Bereiche) mit eingerückten Unterseiten; Archiviertes steht getrennt am Ende. */
    public function treeRows(array $rows): array
    {
        $active = array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') !== 'archived'));
        $archived = array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'archived'));
        $out = self::nest($active);
        foreach (self::nest($archived) as $i => $r) {
            $r['_group'] = $i === 0 ? 'Archiviert' : '';
            $out[] = $r;
        }
        return $out;
    }

    public function columns(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel'],
            ['key' => 'slug', 'label' => 'Adresse'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status:content_status'],
        ];
    }

    public function fields(?array $row): array
    {
        $parents = array_column($this->db->all('SELECT id, title FROM services WHERE parent_id IS NULL AND id <> ? ORDER BY sort_order', [$row['id'] ?? 0]), 'title', 'id');
        $icons = array_combine(self::ICONS, self::ICONS);
        return [
            ['name' => 'title', 'label' => 'Titel', 'required' => true, 'max' => 200],
            ['name' => 'menu_title', 'label' => 'Kurztitel für Menüs und Karten', 'max' => 80, 'help' => 'Möglichst kurz, damit der Titel auf Karten in eine Zeile passt, z. B. „Sprachkurse“.'],
            ['name' => 'slug', 'label' => 'Adresse', 'type' => 'slug', 'required' => true, 'from' => 'title'],
            ['name' => 'parent_id', 'label' => 'Übergeordnete Leistung', 'type' => 'select', 'options' => $parents, 'placeholder' => '– keine (Hauptleistung) –', 'numeric' => true],
            ['name' => 'icon', 'label' => 'Symbol', 'type' => 'select', 'options' => $icons, 'placeholder' => '– keins –'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => Labels::options('content_status'), 'default' => 'draft'],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0],
            ['name' => 'teaser', 'label' => 'Kurztext für Karten', 'type' => 'textarea', 'required' => true, 'max' => 400, 'rows' => 2, 'group' => 'Inhalt'],
            ['name' => 'intro', 'label' => 'Einleitung', 'type' => 'textarea', 'max' => 600, 'rows' => 3, 'group' => 'Inhalt'],
            ['name' => 'body_html', 'label' => 'Text', 'type' => 'html', 'group' => 'Inhalt'],
            ['name' => 'facts_facts', 'label' => 'Eckdaten (eine pro Zeile: Bezeichnung | Wert)', 'type' => 'lines', 'virtual' => true, 'group' => 'Bausteine', 'help' => 'z. B. „Umfang | 120 Unterrichtseinheiten“'],
            ['name' => 'facts_modules', 'label' => 'Bausteine (eine pro Zeile: Symbol | Titel | Text)', 'type' => 'lines', 'virtual' => true, 'rows' => 6, 'group' => 'Bausteine', 'help' => 'Symbol ist optional, z. B. „house | Wohnungssuche | Wir helfen bei …“. Verfügbare Symbole: ' . implode(', ', self::ICONS)],
            ['name' => 'facts_process', 'label' => 'Ablauf in Schritten (eine pro Zeile: Titel | Text)', 'type' => 'lines', 'virtual' => true, 'rows' => 5, 'group' => 'Bausteine'],
            ['name' => 'facts_highlights', 'label' => 'Kernpunkte unter dem Seitenkopf (einer pro Zeile)', 'type' => 'lines', 'virtual' => true, 'rows' => 3, 'group' => 'Abschnitte der Seite'],
            ['name' => 'facts_sections', 'label' => 'Abschnitte (eine pro Zeile: Kleine Überschrift | Überschrift | Text)', 'type' => 'lines', 'virtual' => true, 'rows' => 6, 'group' => 'Abschnitte der Seite', 'help' => 'Reihenfolge wie auf der Seite. Die Zeilennummer (1, 2, 3 …) wird unten für Karten und Box-Punkte gebraucht. Ein Abschnitt mit Karten zeigt die Karten, sonst rechts eine Symbol-Box.'],
            ['name' => 'facts_cards', 'label' => 'Karten in Abschnitten (eine pro Zeile: Abschnittsnummer | Titel | Text)', 'type' => 'lines', 'virtual' => true, 'rows' => 6, 'group' => 'Abschnitte der Seite', 'help' => 'z. B. „1 | Berufliche Grundlagen | Aufgaben lesen …“ = Karte im ersten Abschnitt.'],
            ['name' => 'facts_points', 'label' => 'Punkte der Symbol-Box (eine pro Zeile: Abschnittsnummer | Titel | Text)', 'type' => 'lines', 'virtual' => true, 'rows' => 4, 'group' => 'Abschnitte der Seite', 'help' => 'Nur nötig, wenn die Box andere Punkte zeigen soll als die Standardpunkte. Leer lassen = Standard bleibt.'],
            ['name' => 'facts_process_eyebrow', 'label' => 'Ablauf: kleine Überschrift', 'max' => 80, 'virtual' => true, 'group' => 'Abschnitte der Seite'],
            ['name' => 'facts_process_title', 'label' => 'Ablauf: Überschrift', 'max' => 200, 'virtual' => true, 'group' => 'Abschnitte der Seite', 'help' => 'Die Schritte selbst stehen oben unter „Ablauf in Schritten“.'],
            ['name' => 'facts_faq', 'label' => 'Häufige Fragen (eine pro Zeile: Frage | Antwort)', 'type' => 'lines', 'virtual' => true, 'rows' => 5, 'group' => 'Abschnitte der Seite'],
            ['name' => 'facts_cta_title', 'label' => 'Abschluss-Band: Überschrift', 'max' => 200, 'virtual' => true, 'group' => 'Abschnitte der Seite'],
            ['name' => 'facts_cta_text', 'label' => 'Abschluss-Band: Text', 'type' => 'textarea', 'max' => 400, 'rows' => 2, 'virtual' => true, 'group' => 'Abschnitte der Seite'],
            ['name' => 'cta_label', 'label' => 'Text der Handlungsschaltfläche', 'max' => 80, 'group' => 'Handlung'],
            ['name' => 'cta_url', 'label' => 'Ziel der Schaltfläche', 'type' => 'url', 'max' => 255, 'group' => 'Handlung'],
            ['name' => 'download_media_label', 'label' => 'Download: Bezeichnung', 'max' => 120, 'group' => 'Handlung'],
            ['name' => 'download_url', 'label' => 'Download: Adresse (z. B. PDF aus der Mediathek)', 'type' => 'url', 'max' => 255, 'group' => 'Handlung'],
            ['name' => 'hero_media_id', 'label' => 'Bild oben', 'type' => 'media', 'group' => 'Bilder'],
            ['name' => 'card_media_id', 'label' => 'Bild für Karten', 'type' => 'media', 'group' => 'Bilder'],
            ['name' => 'seo_title', 'label' => 'SEO-Titel', 'max' => 70, 'counter' => 60, 'group' => 'Suchmaschinen'],
            ['name' => 'seo_description', 'label' => 'Meta-Beschreibung', 'type' => 'textarea', 'max' => 170, 'counter' => 160, 'rows' => 2, 'group' => 'Suchmaschinen'],
        ];
    }

    public function toForm(array $row): array
    {
        return array_key_exists('facts_json', $row) ? array_merge($row, self::factsToLines($row['facts_json'] ?? null)) : $row;
    }

    /** Die Bausteine liegen als JSON in facts_json; für das Formular werden sie in Zeilen zerlegt. @return array<string,string> */
    public static function factsToLines(?string $json): array
    {
        $f = $json ? (json_decode($json, true) ?: []) : [];
        return [
            'facts_facts' => json_encode(array_map(fn($x) => $x['label'] . ' | ' . $x['value'], $f['facts'] ?? []), JSON_UNESCAPED_UNICODE),
            'facts_modules' => json_encode(array_map(fn($x) => (($x['icon'] ?? '') !== '' ? $x['icon'] . ' | ' : '') . $x['title'] . ' | ' . $x['text'], $f['modules'] ?? []), JSON_UNESCAPED_UNICODE),
            'facts_process' => json_encode(array_map(fn($x) => $x['title'] . ' | ' . $x['text'], $f['process'] ?? []), JSON_UNESCAPED_UNICODE),
        ] + self::sectionsToLines($f);
    }

    /** Abschnitte, Karten, Box-Punkte, Fragen und Abschluss-Band für das Formular. @param array<string,mixed> $f @return array<string,string> */
    private static function sectionsToLines(array $f): array
    {
        $one = fn($v) => trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        $sections = $cards = $points = [];
        foreach (array_values((array) ($f['sections'] ?? [])) as $i => $sec) {
            $n = $i + 1;
            $sections[] = $one($sec['eyebrow'] ?? '') . ' | ' . $one($sec['title'] ?? '') . ' | ' . $one($sec['text'] ?? '');
            foreach ((array) ($sec['cards'] ?? []) as $c) {
                $cards[] = $n . ' | ' . $one($c['title'] ?? '') . ' | ' . $one($c['text'] ?? '');
            }
            foreach ((array) ($sec['points'] ?? []) as $pt) {
                $points[] = $n . ' | ' . $one($pt['title'] ?? '') . ' | ' . $one($pt['text'] ?? '');
            }
        }
        $enc = fn(array $a) => json_encode($a, JSON_UNESCAPED_UNICODE);
        return [
            'facts_highlights' => $enc(array_map($one, array_values((array) ($f['highlights'] ?? [])))),
            'facts_sections' => $enc($sections),
            'facts_cards' => $enc($cards),
            'facts_points' => $enc($points),
            'facts_faq' => $enc(array_map(fn($x) => $one($x['q'] ?? '') . ' | ' . $one($x['a'] ?? ''), array_values((array) ($f['faq_items'] ?? [])))),
            'facts_process_eyebrow' => (string) ($f['process_eyebrow'] ?? ''),
            'facts_process_title' => (string) ($f['process_title'] ?? ''),
            'facts_cta_title' => (string) ($f['cta_title'] ?? ''),
            'facts_cta_text' => (string) ($f['cta_text'] ?? ''),
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        if (isset($values['slug'])) {
            $parent = $values['parent_id'] ?? null;
            $dup = $parent === null || $parent === ''
                ? $this->db->value('SELECT 1 FROM services WHERE slug = ? AND parent_id IS NULL AND id <> ?', [$values['slug'], $id ?? 0]) ?? $this->db->value('SELECT 1 FROM pages WHERE slug = ?', [$values['slug']])
                : $this->db->value('SELECT 1 FROM services WHERE slug = ? AND parent_id = ? AND id <> ?', [$values['slug'], $parent, $id ?? 0]);
            if ($dup !== null) {
                $e['slug'] = 'Diese Adresse wird schon verwendet.';
            }
        }
        foreach (['facts_facts' => 2, 'facts_process' => 2] as $k => $parts) {
            foreach ((array) json_decode((string) ($values[$k] ?? '[]'), true) as $i => $line) {
                if (count(array_map('trim', explode('|', (string) $line))) !== $parts) {
                    $e[$k] = 'Zeile ' . ($i + 1) . ': bitte genau ' . ($parts - 1) . ' senkrechten Strich „|“ verwenden.';
                    break;
                }
            }
        }
        $secCount = count((array) json_decode((string) ($values['facts_sections'] ?? '[]'), true));
        foreach (['facts_sections' => [3, 'Kleine Überschrift | Überschrift | Text'], 'facts_cards' => [3, 'Abschnittsnummer | Titel | Text'], 'facts_points' => [3, 'Abschnittsnummer | Titel | Text'], 'facts_faq' => [2, 'Frage | Antwort']] as $k => [$parts, $format]) {
            foreach ((array) json_decode((string) ($values[$k] ?? '[]'), true) as $i => $line) {
                $p = array_map('trim', explode('|', (string) $line, $parts));
                if (count($p) !== $parts) {
                    $e[$k] = 'Zeile ' . ($i + 1) . ': bitte im Format „' . $format . '“ eintragen.';
                    break;
                }
                if (in_array($k, ['facts_cards', 'facts_points'], true) && (!ctype_digit($p[0]) || (int) $p[0] < 1 || (int) $p[0] > $secCount)) {
                    $e[$k] = 'Zeile ' . ($i + 1) . ': Die Abschnittsnummer muss zwischen 1 und ' . $secCount . ' liegen (Anzahl der Abschnitte).';
                    break;
                }
            }
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        $lines = fn(string $k) => array_map(fn($l) => array_map('trim', explode('|', (string) $l)), (array) json_decode((string) ($values[$k] ?? '[]'), true));
        // Alle bisherigen Angaben (Abschnitte, Kernpunkte, Fragen, Abschluss-Band, Spruch …) bleiben erhalten; nur die Felder des Formulars werden ersetzt.
        $facts = is_string($existing['facts_json'] ?? null) ? ((array) json_decode($existing['facts_json'], true)) : [];
        unset($facts['facts'], $facts['modules'], $facts['process']);
        if ($f = $lines('facts_facts')) {
            $facts['facts'] = array_map(fn($p) => ['label' => $p[0], 'value' => $p[1] ?? ''], $f);
        }
        if ($m = $lines('facts_modules')) {
            $facts['modules'] = array_map(function ($p) {
                $icon = null;
                if (count($p) >= 3) {
                    $first = array_shift($p);
                    $icon = in_array($first, self::ICONS, true) ? $first : null;
                }
                return ['icon' => $icon, 'title' => $p[0] ?? '', 'text' => implode(' | ', array_slice($p, 1))];
            }, $m);
        }
        if ($p = $lines('facts_process')) {
            $facts['process'] = array_map(fn($x) => ['title' => $x[0], 'text' => $x[1] ?? ''], $p);
        }
        $facts = $this->applySections($facts, $values, $lines);
        $values['facts_json'] = $facts === [] ? null : json_encode($facts, JSON_UNESCAPED_UNICODE);
        foreach (['parent_id', 'icon'] as $k) {
            if (($values[$k] ?? '') === '') {
                $values[$k] = null;
            }
        }
        return $values;
    }

    /**
     * Abschnitte, Karten, Box-Punkte, Fragen und Abschluss-Band aus dem Formular in die Bausteine übernehmen.
     * Weitere Angaben eines Abschnitts (z. B. Symbol) bleiben je Abschnittsnummer erhalten.
     * @param array<string,mixed> $facts @param array<string,mixed> $values @return array<string,mixed>
     */
    private function applySections(array $facts, array $values, \Closure $lines): array
    {
        if (!array_key_exists('facts_sections', $values)) {
            return $facts;
        }
        $old = array_values((array) ($facts['sections'] ?? []));
        $cards = [];
        foreach ($lines('facts_cards') as $p) {
            $cards[(int) $p[0]][] = ['title' => $p[1], 'text' => implode(' | ', array_slice($p, 2))];
        }
        $points = [];
        foreach ($lines('facts_points') as $p) {
            $points[(int) $p[0]][] = ['title' => $p[1], 'text' => implode(' | ', array_slice($p, 2))];
        }
        $sections = [];
        foreach ($lines('facts_sections') as $i => $p) {
            $n = $i + 1;
            $sec = is_array($old[$i] ?? null) ? $old[$i] : [];
            unset($sec['cards']);
            $sec['eyebrow'] = $p[0];
            $sec['title'] = $p[1];
            $sec['text'] = implode(' | ', array_slice($p, 2));
            if (isset($cards[$n])) {
                $sec['cards'] = $cards[$n];
            }
            if (isset($points[$n])) {
                // vorhandene Symbole der Punkte bleiben, sofern der Titel gleich geblieben ist
                $oldPts = array_column((array) ($sec['points'] ?? []), null, 'title');
                $sec['points'] = array_map(fn($pt) => isset($oldPts[$pt['title']]['icon']) ? $pt + ['icon' => $oldPts[$pt['title']]['icon']] : $pt, $points[$n]);
            }
            $sections[] = $sec;
        }
        $sections === [] ? $facts = array_diff_key($facts, ['sections' => 1]) : $facts['sections'] = $sections;

        $hl = array_values(array_filter(array_map('trim', (array) json_decode((string) ($values['facts_highlights'] ?? '[]'), true)), fn($x) => $x !== ''));
        $hl === [] ? $facts = array_diff_key($facts, ['highlights' => 1]) : $facts['highlights'] = $hl;
        $faq = array_map(fn($p) => ['q' => $p[0], 'a' => implode(' | ', array_slice($p, 1))], $lines('facts_faq'));
        $faq === [] ? $facts = array_diff_key($facts, ['faq_items' => 1]) : $facts['faq_items'] = $faq;
        foreach (['process_eyebrow' => 'facts_process_eyebrow', 'process_title' => 'facts_process_title', 'cta_title' => 'facts_cta_title', 'cta_text' => 'facts_cta_text'] as $key => $field) {
            $v = trim((string) ($values[$field] ?? ''));
            $v === '' ? $facts = array_diff_key($facts, [$key => 1]) : $facts[$key] = $v;
        }
        return $facts;
    }

    public function actions(array $row, AdminContext $ctx): array
    {
        return [['label' => 'Als Seite im Seitenbaukasten bearbeiten', 'url' => '/verwaltung/leistungen/' . $row['id'] . '/als-seite/', 'icon' => 'layout-template', 'post' => true,
            'confirm' => 'Eine Entwurfsseite mit denselben Inhalten anlegen? Die Leistungsseite bleibt unverändert online, bis Sie die neue Seite veröffentlichen.']];
    }

    public function deleteBlocker(int $id): ?string
    {
        return $this->db->value('SELECT 1 FROM services WHERE parent_id = ? LIMIT 1', [$id]) !== null
            ? 'Diese Leistung hat Unterleistungen. Löschen oder verschieben Sie diese zuerst.' : null;
    }

    public function publicUrl(array $row): ?string
    {
        if ($row['status'] !== 'published') {
            return null;
        }
        $parent = $row['parent_id'] ? $this->db->value('SELECT slug FROM services WHERE id = ?', [$row['parent_id']]) : null;
        return '/' . ($parent ? $parent . '/' : '') . $row['slug'] . '/';
    }

    public function preview(array $row): ?string
    {
        return ($this->previewer)($row);
    }
}
