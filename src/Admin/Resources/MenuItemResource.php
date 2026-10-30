<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;
use Mea\Core\Request;

final class MenuItemResource extends Resource
{
    public string $key = 'menues';
    public string $table = 'menu_items';
    public string $permission = 'content';
    public string $singular = 'Menüeintrag';
    public string $plural = 'Menüs';
    public string $entity = 'menu_item';
    public string $intro = 'Hauptmenü, Footer und Rechtliches. Einträge mit übergeordnetem Eintrag erscheinen als Untermenü.';
    public string $orderBy = 'menu_name, parent_sort, parent_id IS NOT NULL, sort_order';
    public bool $optimisticLock = false;

    public function baseQuery(): string
    {
        return 'SELECT i.*, m.name AS menu_name, p.label AS parent_label, COALESCE(p.sort_order, i.sort_order) AS parent_sort
            FROM menu_items i JOIN menus m ON m.id = i.menu_id LEFT JOIN menu_items p ON p.id = i.parent_id';
    }

    public function filters(): array
    {
        return [['name' => 'menu', 'label' => 'Menü', 'options' => array_column($this->db->all('SELECT id, name FROM menus ORDER BY id'), 'name', 'id')]];
    }

    public function filterWhere(Request $req): array
    {
        return ctype_digit($req->input('menu')) ? ['menu_id = ?', [(int) $req->input('menu')]] : ['', []];
    }

    public function columns(): array
    {
        return [
            ['key' => 'label', 'label' => 'Bezeichnung'],
            ['key' => 'menu_name', 'label' => 'Menü'],
            ['key' => 'parent_label', 'label' => 'Unter'],
            ['key' => 'url', 'label' => 'Ziel'],
            ['key' => 'sort_order', 'label' => 'Sortierung', 'format' => 'int'],
        ];
    }

    public function titleOf(array $row): string
    {
        return (string) ($row['label'] ?? 'Menüeintrag');
    }

    public function fields(?array $row): array
    {
        $parents = [];
        foreach ($this->db->all('SELECT i.id, i.label, m.name FROM menu_items i JOIN menus m ON m.id = i.menu_id WHERE i.parent_id IS NULL AND i.id <> ? ORDER BY m.id, i.sort_order', [$row['id'] ?? 0]) as $p) {
            $parents[$p['id']] = $p['name'] . ' › ' . $p['label'];
        }
        return [
            ['name' => 'menu_id', 'label' => 'Menü', 'type' => 'select', 'required' => true, 'numeric' => true, 'options' => array_column($this->db->all('SELECT id, name FROM menus ORDER BY id'), 'name', 'id')],
            ['name' => 'parent_id', 'label' => 'Übergeordneter Eintrag', 'type' => 'select', 'options' => $parents, 'placeholder' => '– keiner –', 'numeric' => true],
            ['name' => 'label', 'label' => 'Bezeichnung', 'required' => true, 'max' => 80],
            ['name' => 'url', 'label' => 'Ziel', 'type' => 'url', 'required' => true, 'max' => 255, 'help' => 'Interne Seiten mit / beginnen, z. B. /kontakt/'],
            ['name' => 'description', 'label' => 'Kurzbeschreibung im Untermenü', 'max' => 160],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0],
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        $p = $values['parent_id'] ?? null;
        if ($p !== null && $p !== '' && (int) $this->db->value('SELECT menu_id FROM menu_items WHERE id = ?', [$p]) !== (int) ($values['menu_id'] ?? 0)) {
            return ['parent_id' => 'Der übergeordnete Eintrag gehört zu einem anderen Menü.'];
        }
        return [];
    }

    public function beforeSave(array $values, ?array $existing, \Mea\Admin\AdminContext $ctx): array
    {
        $values['parent_id'] = ($values['parent_id'] ?? '') === '' ? null : (int) $values['parent_id'];
        return $values;
    }

    public function deleteBlocker(int $id): ?string
    {
        return $this->db->value('SELECT 1 FROM menu_items WHERE parent_id = ? LIMIT 1', [$id]) !== null ? 'Dieser Eintrag hat Untereinträge. Löschen Sie diese zuerst.' : null;
    }
}
