<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;

final class ProviderResource extends Resource
{
    public string $key = 'anbieter';
    public string $table = 'exam_providers';
    public string $permission = 'exams';
    public string $singular = 'Anbieter';
    public string $plural = 'Prüfungsanbieter';
    public string $entity = 'exam_provider';
    public string $intro = 'Zertifizierungsorganisationen wie telc. Prüfungsarten werden einem Anbieter zugeordnet.';
    public string $orderBy = 'name';
    public bool $optimisticLock = false;

    public function columns(): array
    {
        return [['key' => 'name', 'label' => 'Name'], ['key' => 'website', 'label' => 'Website']];
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120],
            ['name' => 'website', 'label' => 'Website', 'type' => 'url', 'max' => 255],
            ['name' => 'logo_media_id', 'label' => 'Logo', 'type' => 'media'],
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        return isset($values['name']) && $this->isTaken('name', $values['name'], $id) ? ['name' => 'Diesen Anbieter gibt es schon.'] : [];
    }

    public function deleteBlocker(int $id): ?string
    {
        return $this->db->value('SELECT 1 FROM exam_types WHERE provider_id = ? LIMIT 1', [$id]) !== null
            ? 'Dieser Anbieter ist Prüfungsarten zugeordnet und kann nicht gelöscht werden.' : null;
    }
}
