<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Admin\Labels;
use Mea\Core\Database;

/** Freie Seiten und Systemseiten (Texte von Kontakt, FAQ, Preise usw.). */
final class PageResource extends Resource
{
    public string $key = 'seiten';
    public string $table = 'pages';
    public string $permission = 'content';
    public string $singular = 'Seite';
    public string $plural = 'Seiten';
    public string $entity = 'page';
    public string $intro = 'Texte der Website. Systemseiten (z. B. Kontakt, Preise) haben feste Adressen; hier pflegen Sie deren Überschrift, Einleitung und SEO-Angaben.';
    public string $orderBy = 'is_system DESC, title';
    public array $searchColumns = ['title', 'slug'];
    public ?string $revisionEntity = 'page';

    private const RESERVED = ['verwaltung', 'pruefungen', 'buchung', 'zahlung', 'assets', 'media', 'standorte', 'sitemap-xml', 'robots-txt'];

    /** @var \Closure(array<string,mixed>):string */
    private \Closure $previewer;

    public function __construct(Database $db, \Closure $previewer)
    {
        parent::__construct($db);
        $this->previewer = $previewer;
    }

    public function columns(): array
    {
        return [
            ['key' => 'title', 'label' => 'Titel'],
            ['key' => 'slug', 'label' => 'Adresse'],
            ['key' => 'template', 'label' => 'Vorlage'],
            ['key' => 'updated_at', 'label' => 'Geändert', 'format' => 'datetime'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status:content_status'],
        ];
    }

    public function fields(?array $row): array
    {
        $system = (int) ($row['is_system'] ?? 0) === 1;
        return [
            ['name' => 'title', 'label' => 'Titel (Überschrift)', 'required' => true, 'max' => 200],
            ['name' => 'slug', 'label' => 'Adresse', 'type' => 'slug', 'required' => true, 'from' => 'title', 'readonly' => $system,
                'help' => $system ? 'Systemseite: Die Adresse ist fest.' : 'Ergibt /adresse/. Nach Veröffentlichung möglichst nicht ändern.'],
            ['name' => 'template', 'label' => 'Vorlage', 'type' => 'select', 'required' => true, 'options' => ['default' => 'Standard', 'legal' => 'Rechtstext', 'about' => 'Über uns (mit Bewertungen und Standorten)'], 'default' => 'default'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => Labels::options('content_status'), 'default' => 'draft', 'readonly' => $system],
            ['name' => 'intro', 'label' => 'Einleitung', 'type' => 'textarea', 'max' => 500, 'rows' => 3, 'group' => 'Inhalt'],
            ['name' => 'body_html', 'label' => 'Inhalt', 'type' => 'html', 'group' => 'Inhalt'],
            ['name' => 'hero_media_id', 'label' => 'Bild oben', 'type' => 'media', 'group' => 'Inhalt'],
            ['name' => 'seo_title', 'label' => 'SEO-Titel', 'max' => 70, 'counter' => 60, 'group' => 'Suchmaschinen', 'help' => 'Leer = Titel.'],
            ['name' => 'seo_description', 'label' => 'Meta-Beschreibung', 'type' => 'textarea', 'max' => 170, 'counter' => 160, 'rows' => 2, 'group' => 'Suchmaschinen'],
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        $slug = $values['slug'] ?? null;
        if ($slug !== null && $slug !== '') {
            if (in_array($slug, self::RESERVED, true)) {
                $e['slug'] = 'Diese Adresse ist für das System reserviert.';
            } elseif ($this->isTaken('slug', $slug, $id) || $this->db->value('SELECT 1 FROM services WHERE slug = ? AND parent_id IS NULL', [$slug]) !== null) {
                $e['slug'] = 'Diese Adresse wird schon verwendet.';
            }
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        if (($values['status'] ?? '') === 'published' && ($existing['published_at'] ?? null) === null) {
            $values['published_at'] = gmdate('Y-m-d H:i:s');
        }
        return $values;
    }

    public function deletable(): bool
    {
        return true;
    }

    public function deleteBlocker(int $id): ?string
    {
        return (int) $this->db->value('SELECT is_system FROM pages WHERE id = ?', [$id]) === 1 ? 'Systemseiten können nicht gelöscht werden.' : null;
    }

    public function publicUrl(array $row): ?string
    {
        if ($row['status'] !== 'published') {
            return null;
        }
        return match ($row['slug']) {
            'telc-pruefungszentrum' => '/telc-pruefungszentrum/',
            default => '/' . $row['slug'] . '/',
        };
    }

    public function preview(array $row): ?string
    {
        return in_array($row['slug'], ['leistungen', 'faq', 'preise', 'kontakt', 'standorte', 'telc-pruefungszentrum'], true) && (int) $row['is_system'] === 1
            ? null : ($this->previewer)($row);
    }
}
