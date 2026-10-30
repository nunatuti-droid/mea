<?php
declare(strict_types=1);

namespace Mea\Admin\Crud;

use Mea\Admin\AdminContext;
use Mea\Core\Database;
use Mea\Core\Request;

/**
 * Beschreibung eines Verwaltungsbereichs (Tabelle, Felder, Liste, Regeln).
 * Der CrudController baut daraus Liste, Formular, Speichern und Löschen.
 */
abstract class Resource
{
    public string $key;
    public string $table;
    public string $permission;
    public string $singular;
    public string $plural;
    public string $entity;
    public string $intro = '';
    public string $orderBy = 'id DESC';
    /** @var list<string> Spalten für die Suche */
    public array $searchColumns = [];
    public bool $creatable = true;
    public bool $optimisticLock = true;
    /** Versionshistorie und Entwürfe (content_revisions) für diesen Bereich */
    public ?string $revisionEntity = null;
    /** Zusätzliches Template oberhalb der Liste */
    public ?string $indexPanel = null;

    public function __construct(protected Database $db) {}

    /** @param array<string,mixed>|null $row @return list<array<string,mixed>> */
    abstract public function fields(?array $row): array;

    /** @return list<array{key:string, label:string, format?:string}> */
    abstract public function columns(): array;

    /** @param array<string,mixed> $row */
    public function titleOf(array $row): string
    {
        return (string) ($row['title'] ?? $row['name'] ?? $row['public_name'] ?? ('#' . $row['id']));
    }

    public function baseQuery(): string
    {
        return 'SELECT * FROM ' . $this->table;
    }

    /** @return array{0:string, 1:list<mixed>} zusätzliche WHERE-Bedingung aus Filtern */
    public function filterWhere(Request $req): array
    {
        return ['', []];
    }

    /** @return list<array{name:string, label:string, options:array<string,string>}> */
    public function filters(): array
    {
        return [];
    }

    /** Datensatz für die Formularanzeige aufbereiten (z. B. JSON in Zeilen zerlegen). @param array<string,mixed> $row @return array<string,mixed> */
    public function toForm(array $row): array
    {
        return $row;
    }

    /** Vorbelegung beim Anlegen. @return array<string,mixed> */
    public function prefill(Request $req): array
    {
        return [];
    }

    /** @param array<string,mixed> $values @return array<string,string> */
    public function validate(array $values, ?int $id): array
    {
        return [];
    }

    /** @param array<string,mixed> $values @param array<string,mixed>|null $existing @return array<string,mixed> */
    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        return $values;
    }

    /** @param array<string,mixed> $values @param array<string,mixed>|null $existing */
    public function afterSave(int $id, array $values, ?array $existing, AdminContext $ctx, Request $req): void {}

    /** Grund, warum nicht gelöscht werden darf, oder null. */
    public function deleteBlocker(int $id): ?string
    {
        return null;
    }

    public function deletable(): bool
    {
        return true;
    }

    /** @return list<array{template:string, data:array<string,mixed>}> Zusatzbereiche auf der Bearbeiten-Seite */
    public function panels(int $id, AdminContext $ctx): array
    {
        return [];
    }

    /** @param array<string,mixed> $row */
    public function publicUrl(array $row): ?string
    {
        return null;
    }

    /** Vorschau eines (Entwurfs-)Stands mit dem öffentlichen Template. @param array<string,mixed> $row */
    public function preview(array $row): ?string
    {
        return null;
    }

    /** Zusätzliche Knöpfe im Kopf der Bearbeiten-Seite. @param array<string,mixed> $row @return list<array{label:string, url:string, icon:string, post?:bool, confirm?:string}> */
    /** Schaltflächen je Zeile in der Liste (POST mit Feld „value“). @return list<array<string,string>> */
    public function rowActions(array $row): array
    {
        return [];
    }

    public function actions(array $row, AdminContext $ctx): array
    {
        return [];
    }

    /** Eindeutigkeit eines Feldes prüfen. */
    protected function isTaken(string $column, mixed $value, ?int $id): bool
    {
        return $this->db->value("SELECT 1 FROM {$this->table} WHERE {$column} = ? AND id <> ?", [$value, $id ?? 0]) !== null;
    }
}
