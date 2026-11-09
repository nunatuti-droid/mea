<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;

final class ReviewResource extends Resource
{
    public string $key = 'bewertungen';
    public string $table = 'reviews';
    public string $permission = 'content';
    public string $singular = 'Bewertung';
    public string $plural = 'Bewertungen';
    public string $entity = 'review';
    public string $intro = 'Manuell gepflegte Stimmen von Teilnehmenden. Bitte nur echte Rückmeldungen mit Einverständnis eintragen. Mit „Ausblenden“ und „Anzeigen“ schalten Sie eine Stimme mit einem Klick ab oder an. Bewertungen von Google pflegen Sie unter „Google-Bewertungen“.';
    public string $orderBy = 'sort_order, id';

    public function columns(): array
    {
        return [
            ['key' => 'display_name', 'label' => 'Name'],
            ['key' => 'role_label', 'label' => 'Beschreibung'],
            ['key' => 'rating', 'label' => 'Sterne', 'format' => 'int'],
            ['key' => 'is_active', 'label' => 'Aktiv', 'format' => 'status:bool'],
        ];
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'display_name', 'label' => 'Anzeigename', 'required' => true, 'max' => 80, 'help' => 'Zum Beispiel Vorname und Initial: „Michael D.“'],
            ['name' => 'role_label', 'label' => 'Beschreibung', 'max' => 120, 'help' => 'z. B. Beruf oder besuchter Kurs'],
            ['name' => 'body', 'label' => 'Text', 'type' => 'textarea', 'required' => true, 'max' => 2000, 'rows' => 4],
            ['name' => 'rating', 'label' => 'Sterne', 'type' => 'select', 'options' => ['' => 'keine Angabe', '5' => '5', '4' => '4', '3' => '3', '2' => '2', '1' => '1'], 'numeric' => true,
                'help' => 'Nur eintragen, wenn die Person selbst eine Sternebewertung abgegeben hat.'],
            ['name' => 'source_note', 'label' => 'Quelle (intern)', 'max' => 200, 'help' => 'z. B. „Feedbackbogen 03/2026, Einverständnis liegt vor“'],
            ['name' => 'is_active', 'label' => 'Auf der Website anzeigen', 'type' => 'checkbox', 'default' => 1],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0],
        ];
    }

    public function rowActions(array $row): array
    {
        $url = '/verwaltung/bewertungen/' . $row['id'] . '/aktiv/';
        return (int) $row['is_active'] === 1
            ? [['label' => 'Ausblenden', 'url' => $url, 'value' => '0', 'icon' => 'ban']]
            : [['label' => 'Anzeigen', 'url' => $url, 'value' => '1', 'icon' => 'check']];
    }

    public function titleOf(array $row): string
    {
        return (string) ($row['display_name'] ?? 'Bewertung');
    }

    public function beforeSave(array $values, ?array $existing, \Mea\Admin\AdminContext $ctx): array
    {
        $values['rating'] = ($values['rating'] ?? '') === '' ? null : (int) $values['rating'];
        return $values;
    }
}
