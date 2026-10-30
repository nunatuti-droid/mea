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
    public string $orderBy = 'parent_sort, parent_id IS NOT NULL, sort_order';
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

    public function columns(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel'],
            ['key' => 'parent_title', 'label' => 'Gehört zu'],
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
            ['name' => 'menu_title', 'label' => 'Kurztitel für Menüs', 'max' => 80],
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
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        $lines = fn(string $k) => array_map(fn($l) => array_map('trim', explode('|', (string) $l)), (array) json_decode((string) ($values[$k] ?? '[]'), true));
        $facts = [];
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
        $values['facts_json'] = $facts === [] ? null : json_encode($facts, JSON_UNESCAPED_UNICODE);
        foreach (['parent_id', 'icon'] as $k) {
            if (($values[$k] ?? '') === '') {
                $values[$k] = null;
            }
        }
        return $values;
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
