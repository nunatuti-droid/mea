<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;
use Mea\Module\Content\SettingsRepository;

/** Google-Bewertungen: automatisch aus dem Unternehmensprofil abgerufen oder von Hand übernommen. */
final class GoogleReviewResource extends Resource
{
    public string $key = 'google-bewertungen';
    public string $table = 'google_reviews';
    public string $permission = 'content';
    public string $singular = 'Google-Bewertung';
    public string $plural = 'Google-Bewertungen';
    public string $entity = 'google_review';
    public string $intro = 'Sterne und Bewertungen aus dem Google-Unternehmensprofil. Sie erscheinen oben auf der Startseite (Sterne) und im Fußbereich jeder Seite (Gesamtwert und die drei neuesten sichtbaren Bewertungen). Mit „Ausblenden“ nehmen Sie eine einzelne Bewertung von der Website.';
    public string $orderBy = 'sort_order, published_at IS NULL, published_at DESC, id DESC';
    public ?string $indexPanel = 'admin/google_reviews_panel.twig';

    public function columns(): array
    {
        return [
            ['key' => 'author_name', 'label' => 'Name'],
            ['key' => 'rating', 'label' => 'Sterne', 'format' => 'int'],
            ['key' => 'published_at', 'label' => 'Datum', 'format' => 'date'],
            ['key' => 'source_label', 'label' => 'Herkunft'],
            ['key' => 'is_active', 'label' => 'Auf der Website', 'format' => 'status:bool'],
        ];
    }

    public function baseQuery(): string
    {
        return "SELECT g.*, IF(g.source = 'google', 'automatisch', 'von Hand') AS source_label FROM google_reviews g";
    }

    public function fields(?array $row): array
    {
        $auto = ($row['source'] ?? 'manual') === 'google';
        $note = $auto ? 'Automatisch von Google übernommen, wird beim nächsten Abruf aktualisiert.' : null;
        $fields = [
            ['name' => 'author_name', 'label' => 'Name wie bei Google', 'required' => true, 'max' => 120, 'readonly' => $auto, 'help' => $note],
            ['name' => 'rating', 'label' => 'Sterne', 'type' => 'select', 'options' => ['5' => '5', '4' => '4', '3' => '3', '2' => '2', '1' => '1'], 'numeric' => true, 'required' => true, 'readonly' => $auto],
            ['name' => 'body', 'label' => 'Text', 'type' => 'textarea', 'max' => 4000, 'rows' => 5, 'readonly' => $auto,
                'help' => $auto ? null : 'Den Text genau so übernehmen, wie er bei Google steht.'],
            ['name' => 'published_at', 'label' => 'Datum der Bewertung', 'type' => 'date', 'readonly' => $auto],
            ['name' => 'is_active', 'label' => 'Auf der Website anzeigen', 'type' => 'checkbox', 'default' => 1],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0, 'help' => 'Kleinere Zahl = weiter vorne. Bei gleicher Zahl erscheinen die neuesten zuerst.'],
        ];
        return array_map(fn(array $f) => array_filter($f, fn($v) => $v !== null), $fields);
    }

    public function beforeSave(array $values, ?array $existing, \Mea\Admin\AdminContext $ctx): array
    {
        if (($existing['source'] ?? 'manual') === 'google') {
            foreach (['author_name', 'rating', 'body', 'published_at'] as $k) {
                unset($values[$k]);
            }
            return $values;
        }
        $values['rating'] = max(1, min(5, (int) ($values['rating'] ?? 5)));
        $values['source'] = 'manual';
        return $values;
    }

    public function rowActions(array $row): array
    {
        $url = '/verwaltung/google-bewertungen/' . $row['id'] . '/aktiv/';
        return (int) $row['is_active'] === 1
            ? [['label' => 'Ausblenden', 'url' => $url, 'value' => '0', 'icon' => 'ban']]
            : [['label' => 'Anzeigen', 'url' => $url, 'value' => '1', 'icon' => 'check']];
    }

    public function titleOf(array $row): string
    {
        return (string) ($row['author_name'] ?? 'Google-Bewertung');
    }

    /** @return array<string,mixed> Werte für die Verbindungs-Box über der Liste */
    public function connection(): array
    {
        $s = new SettingsRepository($this->db);
        $g = (array) ($s->get('google', []) ?? []);
        $api = (array) ($s->get('google_api', []) ?? []);
        return [
            'profile_url' => (string) ($g['profile_url'] ?? ''),
            'rating' => (string) ($g['rating'] ?? ''),
            'count' => (string) ($g['count'] ?? ''),
            'as_of' => (string) ($g['as_of'] ?? ''),
            'has_key' => ($api['key'] ?? '') !== '',
            'place_id' => (string) ($api['place_id'] ?? ''),
            'last_sync_at' => (string) ($api['last_sync_at'] ?? ''),
            'last_error' => (string) ($api['last_error'] ?? ''),
            'last_error_at' => (string) ($api['last_error_at'] ?? ''),
        ];
    }
}
