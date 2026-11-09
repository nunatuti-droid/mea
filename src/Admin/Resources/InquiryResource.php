<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;
use Mea\Admin\Labels;
use Mea\Core\Request;
use Mea\Module\Inquiries\InquiryService;

final class InquiryResource extends Resource
{
    public string $key = 'anfragen';
    public string $table = 'inquiries';
    public string $permission = 'inquiries';
    public string $singular = 'Anfrage';
    public string $plural = 'Anfragen';
    public string $entity = 'inquiry';
    public string $intro = 'Nachrichten aus dem Kontaktformular.';
    public string $orderBy = 'created_at DESC';
    public array $searchColumns = ['name', 'email', 'message'];
    public bool $creatable = false;
    public bool $optimisticLock = false;

    public function baseQuery(): string
    {
        return 'SELECT i.*, l.short_name AS location, LEFT(i.message, 80) AS preview FROM inquiries i LEFT JOIN locations l ON l.id = i.location_id';
    }

    public function filters(): array
    {
        return [['name' => 'status', 'label' => 'Status', 'options' => Labels::options('inquiry_status')]];
    }

    public function filterWhere(Request $req): array
    {
        return isset(Labels::MAPS['inquiry_status'][$req->input('status')]) ? ['status = ?', [$req->input('status')]] : ['', []];
    }

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'topic', 'label' => 'Thema'],
            ['key' => 'preview', 'label' => 'Nachricht'],
            ['key' => 'created_at', 'label' => 'Eingang', 'format' => 'datetime'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status:inquiry_status'],
        ];
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => Labels::options('inquiry_status')],
            ['name' => 'name', 'label' => 'Name', 'readonly' => true],
            ['name' => 'email', 'label' => 'E-Mail', 'readonly' => true],
            ['name' => 'phone', 'label' => 'Telefon', 'readonly' => true],
            ['name' => 'topic', 'label' => 'Thema', 'readonly' => true],
            ['name' => 'message', 'label' => 'Nachricht', 'type' => 'textarea', 'readonly' => true, 'rows' => 8],
        ];
    }

    public function toForm(array $row): array
    {
        if (isset($row['topic'])) {
            $row['topic'] = InquiryService::TOPICS[$row['topic']] ?? $row['topic'];
        }
        return $row;
    }

    public function titleOf(array $row): string
    {
        return 'Anfrage von ' . ($row['name'] ?? '#' . ($row['id'] ?? ''));
    }

    public function actions(array $row, \Mea\Admin\AdminContext $ctx): array
    {
        return [['label' => 'Antworten per E-Mail', 'url' => 'mailto:' . rawurlencode((string) $row['email']) . '?subject=' . rawurlencode('Ihre Anfrage beim MEA Bildungszentrum'), 'icon' => 'mail']];
    }
}
