<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Admin\Labels;
use Mea\Core\Database;

/** Prüfungsarten: jede Prüfung ist ein Datensatz, angezeigt über ein gemeinsames Template. */
final class ExamTypeResource extends Resource
{
    public string $key = 'pruefungen';
    public string $table = 'exam_types';
    public string $permission = 'exams';
    public string $singular = 'Prüfungsart';
    public string $plural = 'Prüfungsarten';
    public string $entity = 'exam_type';
    public string $intro = 'Neue Prüfungen legen Sie hier an – ohne Programmierung. Veröffentlichte Prüfungen erscheinen automatisch in Übersicht, Preisliste und Sitemap.';
    public string $orderBy = 'sort_order, public_name';
    public array $searchColumns = ['public_name', 'internal_name', 'slug', 'level_label'];
    public ?string $revisionEntity = 'exam_type';

    /** @var \Closure(array<string,mixed>):?string */
    private \Closure $previewer;

    public function __construct(Database $db, \Closure $previewer)
    {
        parent::__construct($db);
        $this->previewer = $previewer;
    }

    public function baseQuery(): string
    {
        return "SELECT e.*, p.name AS provider_name, c.short_name AS category_name,
            (SELECT COUNT(*) FROM exam_sessions s WHERE s.exam_type_id = e.id AND s.starts_at > UTC_TIMESTAMP() AND s.status <> 'cancelled') AS upcoming
            FROM exam_types e LEFT JOIN exam_providers p ON p.id = e.provider_id LEFT JOIN exam_categories c ON c.id = e.category_id";
    }

    public function filters(): array
    {
        return [
            ['name' => 'status', 'label' => 'Status', 'options' => Labels::options('exam_status')],
            ['name' => 'kategorie', 'label' => 'Kategorie', 'options' => array_column($this->db->all('SELECT id, short_name FROM exam_categories ORDER BY sort_order, name'), 'short_name', 'id')],
        ];
    }

    public function filterWhere(\Mea\Core\Request $req): array
    {
        $where = [];
        $params = [];
        $s = $req->input('status');
        if (isset(Labels::MAPS['exam_status'][$s])) {
            $where[] = 'status = ?';
            $params[] = $s;
        }
        if (ctype_digit($req->input('kategorie'))) {
            $where[] = 'category_id = ?';
            $params[] = (int) $req->input('kategorie');
        }
        return [implode(' AND ', $where), $params];
    }

    public function columns(): array
    {
        return [
            ['key' => 'public_name', 'label' => 'Prüfung'],
            ['key' => 'level_label', 'label' => 'Niveau'],
            ['key' => 'category_name', 'label' => 'Kategorie'],
            ['key' => 'base_price_cents', 'label' => 'Grundpreis', 'format' => 'money'],
            ['key' => 'upcoming', 'label' => 'Kommende Termine', 'format' => 'int'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status:exam_status'],
        ];
    }

    public function fields(?array $row): array
    {
        $providers = array_column($this->db->all('SELECT id, name FROM exam_providers ORDER BY name'), 'name', 'id');
        $rules = array_column($this->db->all('SELECT id, name FROM booking_rules ORDER BY is_default DESC, name'), 'name', 'id');
        $categories = array_column($this->db->all('SELECT id, name FROM exam_categories WHERE is_active = 1 OR id = ? ORDER BY sort_order, name', [$row['category_id'] ?? 0]), 'name', 'id');
        return [
            ['name' => 'public_name', 'label' => 'Öffentlicher Name', 'required' => true, 'max' => 150, 'help' => 'So erscheint die Prüfung auf der Website, z. B. „telc Deutsch B1“.'],
            ['name' => 'internal_name', 'label' => 'Interne Bezeichnung', 'required' => true, 'max' => 150],
            ['name' => 'slug', 'label' => 'URL-Kennung', 'type' => 'slug', 'required' => true, 'from' => 'public_name', 'help' => 'Ergibt die Adresse /pruefungen/kennung/. Nach dem Veröffentlichen möglichst nicht mehr ändern.'],
            ['name' => 'provider_id', 'label' => 'Anbieter / Zertifizierung', 'type' => 'select', 'options' => $providers, 'placeholder' => '– keiner –', 'numeric' => true],
            ['name' => 'level_label', 'label' => 'Niveau oder Kategorie', 'max' => 60, 'help' => 'Frei wählbar, z. B. B1, A2-B1, C1 Hochschule. Wird im Filter angeboten.'],
            ['name' => 'category_id', 'label' => 'Kategorie', 'type' => 'select', 'options' => $categories, 'placeholder' => '– keine –', 'numeric' => true,
                'help' => 'Gliederung der Prüfungsübersicht. Kategorien pflegen Sie unter „Prüfungskategorien“.'],
            ['name' => 'exam_format', 'label' => 'Prüfungsformat', 'max' => 120, 'help' => 'z. B. Gesamtprüfung, Teilprüfung Schriftlich.'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => Labels::options('exam_status'), 'required' => true, 'default' => 'draft',
                'help' => 'Entwurf: noch in Arbeit, unsichtbar. Veröffentlicht: sichtbar und buchbar. Deaktiviert: vorübergehend ausgeblendet, jederzeit wieder veröffentlichbar. Archiviert: dauerhaft eingestellt.'],
            ['name' => 'is_listed', 'label' => 'In Übersichten anzeigen', 'type' => 'checkbox', 'default' => 1],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0, 'help' => 'Kleinere Zahlen stehen weiter vorne.'],

            ['name' => 'short_text', 'label' => 'Kurzbeschreibung', 'type' => 'textarea', 'required' => true, 'max' => 400, 'rows' => 3, 'group' => 'Beschreibung'],
            ['name' => 'body_html', 'label' => 'Ausführliche Beschreibung', 'type' => 'html', 'group' => 'Beschreibung'],
            ['name' => 'target_group_html', 'label' => 'Für wen?', 'type' => 'html', 'group' => 'Beschreibung'],
            ['name' => 'requirements_html', 'label' => 'Voraussetzungen', 'type' => 'html', 'group' => 'Beschreibung'],
            ['name' => 'procedure_html', 'label' => 'Ablauf', 'type' => 'html', 'group' => 'Beschreibung'],
            ['name' => 'duration_text', 'label' => 'Dauer und Hinweise', 'max' => 120, 'group' => 'Beschreibung', 'help' => 'z. B. ca. 3 Stunden, Ausweis mitbringen.'],

            ['name' => 'base_price_cents', 'label' => 'Grundpreis', 'type' => 'money', 'group' => 'Preis und Buchung', 'help' => 'Leer lassen, wenn noch kein Preis feststeht. Einzelne Termine können abweichen.'],
            ['name' => 'currency', 'label' => 'Währung', 'type' => 'select', 'options' => ['EUR' => 'Euro (EUR)'], 'required' => true, 'default' => 'EUR', 'group' => 'Preis und Buchung'],
            ['name' => 'booking_rule_id', 'label' => 'Buchungs- und Stornoregel', 'type' => 'select', 'options' => $rules, 'placeholder' => '– Standardregel –', 'numeric' => true, 'group' => 'Preis und Buchung'],

            ['name' => 'card_media_id', 'label' => 'Bild für Karten', 'type' => 'media', 'group' => 'Bilder'],
            ['name' => 'hero_media_id', 'label' => 'Bild oben auf der Seite', 'type' => 'media', 'group' => 'Bilder'],

            ['name' => 'seo_title', 'label' => 'SEO-Titel', 'max' => 70, 'counter' => 60, 'group' => 'Suchmaschinen', 'help' => 'Leer = öffentlicher Name.'],
            ['name' => 'seo_description', 'label' => 'Meta-Beschreibung', 'type' => 'textarea', 'max' => 170, 'counter' => 160, 'rows' => 2, 'group' => 'Suchmaschinen', 'help' => 'Leer = Kurzbeschreibung.'],
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        if (isset($values['slug']) && $values['slug'] !== '' && $this->isTaken('slug', $values['slug'], $id)) {
            $e['slug'] = 'Diese URL-Kennung ist schon vergeben.';
        }
        if (($values['status'] ?? '') === 'published' && ($values['base_price_cents'] ?? null) === null && $id !== null
            && (int) $this->db->value('SELECT COUNT(*) FROM exam_sessions WHERE exam_type_id = ? AND price_cents IS NULL AND starts_at > UTC_TIMESTAMP()', [$id]) > 0) {
            $e['base_price_cents'] = 'Es gibt Termine ohne eigenen Preis. Bitte einen Grundpreis angeben oder bei den Terminen Preise eintragen.';
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        if (($values['status'] ?? '') === 'published' && ($existing['published_at'] ?? null) === null) {
            $values['published_at'] = gmdate('Y-m-d H:i:s');
        }
        if (($values['booking_rule_id'] ?? '') === '') {
            $values['booking_rule_id'] = null;
        }
        if (($values['provider_id'] ?? '') === '') {
            $values['provider_id'] = null;
        }
        if (($values['category_id'] ?? '') === '') {
            $values['category_id'] = null;
        }
        return $values;
    }

    public function deleteBlocker(int $id): ?string
    {
        if ($this->db->value('SELECT 1 FROM bookings WHERE exam_type_id = ? LIMIT 1', [$id]) !== null) {
            return 'Zu dieser Prüfung gibt es Buchungen. Sie kann nicht gelöscht werden; setzen Sie den Status auf „Archiviert“.';
        }
        if ($this->db->value('SELECT 1 FROM exam_sessions WHERE exam_type_id = ? LIMIT 1', [$id]) !== null) {
            return 'Zu dieser Prüfung gibt es Termine. Löschen Sie zuerst die Termine oder archivieren Sie die Prüfung.';
        }
        return null;
    }

    public function publicUrl(array $row): ?string
    {
        return $row['status'] === 'published' ? '/pruefungen/' . $row['slug'] . '/' : null;
    }

    public function preview(array $row): ?string
    {
        return ($this->previewer)($row);
    }

    /** Schnellschalter in der Liste: deaktivieren bzw. (wieder) veröffentlichen. */
    public function rowActions(array $row): array
    {
        $url = '/verwaltung/pruefungen/' . $row['id'] . '/status/';
        return match ($row['status']) {
            'published' => [['label' => 'Deaktivieren', 'url' => $url, 'value' => 'disabled', 'icon' => 'ban',
                'confirm' => '„' . $row['public_name'] . '“ deaktivieren? Die Prüfung verschwindet sofort von der Website und ist nicht mehr buchbar.']],
            'disabled', 'draft', 'archived' => [['label' => $row['status'] === 'draft' ? 'Veröffentlichen' : 'Wieder veröffentlichen', 'url' => $url, 'value' => 'published', 'icon' => 'check',
                'confirm' => '„' . $row['public_name'] . '“ veröffentlichen? Die Prüfung erscheint sofort auf der Website.']],
            default => [],
        };
    }

    public function actions(array $row, AdminContext $ctx): array
    {
        return [
            ['label' => 'Termine', 'url' => '/verwaltung/termine/?pruefung=' . $row['id'], 'icon' => 'calendar-days'],
            ['label' => 'Als Vorlage kopieren', 'url' => '/verwaltung/pruefungen/' . $row['id'] . '/kopieren/', 'icon' => 'copy', 'post' => true,
                'confirm' => 'Eine Kopie als Entwurf anlegen? Termine und Buchungen werden nicht kopiert.'],
        ];
    }

    public function panels(int $id, AdminContext $ctx): array
    {
        return [
            ['template' => 'admin/exams/fees.twig', 'data' => [
                'exam_id' => $id,
                'fees' => $this->db->all('SELECT * FROM exam_type_fees WHERE exam_type_id = ? ORDER BY sort_order, id', [$id]),
            ]],
            ['template' => 'admin/exams/form_fields.twig', 'data' => [
                'exam_id' => $id,
                'form_fields' => $this->db->all('SELECT * FROM exam_form_fields WHERE exam_type_id = ? ORDER BY sort_order, id', [$id]),
                'field_types' => ['text' => 'Text', 'textarea' => 'Mehrzeiliger Text', 'date' => 'Datum', 'select' => 'Auswahl', 'checkbox' => 'Ankreuzfeld', 'number' => 'Zahl'],
            ]],
            ['template' => 'admin/exams/documents.twig', 'data' => [
                'exam_id' => $id,
                'docs' => $this->db->all('SELECT x.id, x.role, x.label, m.original_name, m.alt_text, m.kind, m.id AS media_id FROM exam_type_media x JOIN media m ON m.id = x.media_id WHERE x.exam_type_id = ? ORDER BY x.sort_order, x.id', [$id]),
                'all_media' => $this->db->all('SELECT id, original_name, alt_text, kind FROM media ORDER BY id DESC LIMIT 500'),
            ]],
        ];
    }
}
