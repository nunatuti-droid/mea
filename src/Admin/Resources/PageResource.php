<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Admin\Labels;
use Mea\Core\Database;
use Mea\Core\Request;
use Mea\Module\Builder\LegacyImport;

/** Freie Seiten und Systemseiten (Texte von Kontakt, FAQ, Preise usw.). */
final class PageResource extends Resource
{
    public string $key = 'seiten';
    public string $table = 'pages';
    public string $permission = 'content';
    public string $singular = 'Seite';
    public string $plural = 'Seiten';
    public string $entity = 'page';
    public string $intro = 'Titel, Adresse, Status und Suchmaschinen-Angaben einer Seite. Den Inhalt bearbeiten Sie im Seitenbaukasten („Visuell bearbeiten“).';
    public string $orderBy = 'is_home DESC, is_system DESC, title';
    public bool $customIndex = true;
    public array $searchColumns = ['title', 'slug'];
    public ?string $revisionEntity = 'page';

    private const RESERVED = ['verwaltung', 'pruefungen', 'buchung', 'zahlung', 'assets', 'media', 'standorte', 'sitemap-xml', 'robots-txt', 'seitenstil', 'einwilligung', 'karriere-bewerbung'];

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
            ['key' => 'updated_at', 'label' => 'Geändert', 'format' => 'datetime'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status:content_status'],
        ];
    }

    public function fields(?array $row): array
    {
        $system = (int) ($row['is_system'] ?? 0) === 1;
        $home = (int) ($row['is_home'] ?? 0) === 1;
        return [
            ['name' => 'title', 'label' => 'Titel', 'required' => true, 'max' => 200, 'help' => 'Name der Seite in der Verwaltung, im Browser-Tab und als Überschrift, wenn der Seitenkopf keine eigene hat.'],
            ['name' => 'slug', 'label' => 'Adresse', 'type' => 'slug', 'required' => true, 'from' => 'title', 'readonly' => $system,
                'help' => $home ? 'Startseite: immer unter / erreichbar.' : ($system ? 'Systemseite: Die Adresse ist fest.' : 'Ergibt /adresse/. Nach Veröffentlichung möglichst nicht ändern; sonst eine Weiterleitung anlegen.')],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => Labels::options('content_status'), 'default' => 'draft', 'readonly' => $system,
                'help' => 'Entwurf und Archiv sind öffentlich nicht erreichbar. Veröffentlichen im Seitenbaukasten setzt den Status auf „veröffentlicht“.'],
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

    public function afterSave(int $id, array $values, ?array $existing, AdminContext $ctx, Request $req): void
    {
        if ($existing === null) {
            // Neue Seite: leerer Aufbau mit Seitenkopf und Textabschnitt als Entwurf
            $this->db->run('UPDATE pages SET blocks_json = ?, draft_json = ? WHERE id = ?', ['[]', json_encode(LegacyImport::blank(), JSON_UNESCAPED_UNICODE), $id]);
        }
    }

    public function actions(array $row, AdminContext $ctx): array
    {
        return [['label' => 'Visuell bearbeiten', 'url' => '/verwaltung/seiten/' . $row['id'] . '/editor/', 'icon' => 'pencil']];
    }

    public function deletable(): bool
    {
        return true;
    }

    public function deleteBlocker(int $id): ?string
    {
        $row = $this->db->one('SELECT is_system, is_home FROM pages WHERE id = ?', [$id]);
        if ((int) ($row['is_home'] ?? 0) === 1) {
            return 'Die Startseite kann nicht gelöscht werden.';
        }
        return (int) ($row['is_system'] ?? 0) === 1 ? 'Systemseiten können nicht gelöscht werden.' : null;
    }

    public function publicUrl(array $row): ?string
    {
        if ($row['status'] !== 'published') {
            return null;
        }
        return (int) ($row['is_home'] ?? 0) === 1 ? '/' : '/' . $row['slug'] . '/';
    }

    public function preview(array $row): ?string
    {
        return ($this->previewer)($row);
    }
}
