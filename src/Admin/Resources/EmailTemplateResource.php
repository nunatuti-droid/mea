<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;

final class EmailTemplateResource extends Resource
{
    public string $key = 'email-vorlagen';
    public string $table = 'email_templates';
    public string $permission = 'emails';
    public string $singular = 'E-Mail-Vorlage';
    public string $plural = 'E-Mail-Vorlagen';
    public string $entity = 'email_template';
    public string $intro = 'Texte der automatischen E-Mails. Platzhalter in doppelten geschweiften Klammern werden beim Versand ersetzt.';
    public string $orderBy = 'name';
    public bool $creatable = false;

    public function deletable(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [['key' => 'name', 'label' => 'Vorlage'], ['key' => 'subject', 'label' => 'Betreff'], ['key' => 'updated_at', 'label' => 'Geändert', 'format' => 'datetime']];
    }

    public function fields(?array $row): array
    {
        $ph = $row ? (array) json_decode((string) ($row['placeholders_json'] ?? '[]'), true) : [];
        return [
            ['name' => 'subject', 'label' => 'Betreff', 'required' => true, 'max' => 200, 'wide' => true],
            ['name' => 'body_html', 'label' => 'Text', 'type' => 'html', 'required' => true,
                'help' => $ph ? 'Verfügbare Platzhalter: ' . implode(', ', array_map(fn($p) => '{{ ' . $p . ' }}', $ph)) : null],
        ];
    }

    public function panels(int $id, \Mea\Admin\AdminContext $ctx): array
    {
        return [['template' => 'admin/emails/template_test.twig', 'data' => ['template_id' => $id]]];
    }

    public function validate(array $values, ?int $id): array
    {
        // Vorlage zur Probe in der Sandbox rendern, damit Syntaxfehler sofort auffallen
        try {
            $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
            $twig->createTemplate((string) ($values['subject'] ?? ''));
            $twig->createTemplate((string) ($values['body_html'] ?? ''));
        } catch (\Twig\Error\Error $e) {
            return ['body_html' => 'Platzhalter fehlerhaft: ' . $e->getRawMessage()];
        }
        return [];
    }

    public function beforeSave(array $values, ?array $existing, \Mea\Admin\AdminContext $ctx): array
    {
        $values['updated_by'] = $ctx->id();
        return $values;
    }
}
