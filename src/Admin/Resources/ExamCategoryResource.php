<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;

/** Kategorien für Prüfungen, z. B. allgemeinsprachlich, Beruf, Pflege und Medizin. */
final class ExamCategoryResource extends Resource
{
    public string $key = 'pruefungskategorien';
    public string $table = 'exam_categories';
    public string $permission = 'exams';
    public string $singular = 'Kategorie';
    public string $plural = 'Prüfungskategorien';
    public string $entity = 'exam_category';
    public string $intro = 'Die Prüfungsübersicht auf der Website wird nach diesen Kategorien gegliedert. Kategorien ohne veröffentlichte Prüfung werden nicht angezeigt.';
    public string $orderBy = 'sort_order, name';
    public bool $optimisticLock = false;

    public function baseQuery(): string
    {
        return 'SELECT c.*, (SELECT COUNT(*) FROM exam_types e WHERE e.category_id = c.id) AS exam_count FROM exam_categories c';
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'short_name', 'label' => 'Kurzname'],
            ['key' => 'exam_count', 'label' => 'Prüfungen', 'format' => 'int'],
            ['key' => 'is_active', 'label' => 'Aktiv', 'format' => 'status:bool'],
        ];
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'help' => 'Überschrift auf der Website, z. B. „Berufsbezogene Deutschprüfungen“.'],
            ['name' => 'short_name', 'label' => 'Kurzname', 'required' => true, 'max' => 60, 'help' => 'Für Filter und Karten, z. B. „Beruf“.'],
            ['name' => 'slug', 'label' => 'URL-Kennung', 'type' => 'slug', 'required' => true, 'from' => 'short_name', 'help' => 'Für den Filter: /telc-pruefungszentrum/?kategorie=kennung'],
            ['name' => 'description', 'label' => 'Einleitungstext', 'type' => 'textarea', 'max' => 500, 'rows' => 3],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0, 'help' => 'Kleinere Zahlen stehen weiter oben.'],
            ['name' => 'is_active', 'label' => 'Aktiv (auf der Website anzeigen)', 'type' => 'checkbox', 'default' => 1],
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        if (isset($values['slug']) && $values['slug'] !== '' && $this->isTaken('slug', $values['slug'], $id)) {
            $e['slug'] = 'Diese URL-Kennung ist schon vergeben.';
        }
        if (isset($values['name']) && $this->isTaken('name', $values['name'], $id)) {
            $e['name'] = 'Diese Kategorie gibt es schon.';
        }
        return $e;
    }

    public function deleteBlocker(int $id): ?string
    {
        return $this->db->value('SELECT 1 FROM exam_types WHERE category_id = ? LIMIT 1', [$id]) !== null
            ? 'Dieser Kategorie sind Prüfungen zugeordnet. Ordnen Sie die Prüfungen zuerst einer anderen Kategorie zu.' : null;
    }
}
