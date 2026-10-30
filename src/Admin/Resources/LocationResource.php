<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Core\Request;

final class LocationResource extends Resource
{
    public string $key = 'standorte';
    public string $table = 'locations';
    public string $permission = 'locations';
    public string $singular = 'Standort';
    public string $plural = 'Standorte';
    public string $entity = 'location';
    public string $orderBy = 'sort_order, id';

    private const DAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'city', 'label' => 'Ort'],
            ['key' => 'phone', 'label' => 'Telefon'],
            ['key' => 'is_active', 'label' => 'Aktiv', 'format' => 'status:bool'],
        ];
    }

    public function fields(?array $row): array
    {
        $f = [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120, 'help' => 'z. B. „Standort Marl (Zentrale)“'],
            ['name' => 'short_name', 'label' => 'Kurzname', 'required' => true, 'max' => 60, 'help' => 'z. B. „Marl“ – für Listen und Kalender'],
            ['name' => 'slug', 'label' => 'Adresse', 'type' => 'slug', 'required' => true, 'from' => 'short_name'],
            ['name' => 'is_headquarters', 'label' => 'Zentrale', 'type' => 'checkbox'],
            ['name' => 'is_active', 'label' => 'Aktiv (auf der Website und für Termine)', 'type' => 'checkbox', 'default' => 1],
            ['name' => 'sort_order', 'label' => 'Sortierung', 'type' => 'int', 'default' => 0],
            ['name' => 'street', 'label' => 'Straße und Hausnummer', 'required' => true, 'max' => 150, 'group' => 'Adresse'],
            ['name' => 'address_extra', 'label' => 'Adresszusatz', 'max' => 150, 'group' => 'Adresse'],
            ['name' => 'postal_code', 'label' => 'PLZ', 'required' => true, 'max' => 10, 'group' => 'Adresse'],
            ['name' => 'city', 'label' => 'Ort', 'required' => true, 'max' => 80, 'group' => 'Adresse'],
            ['name' => 'phone', 'label' => 'Telefon', 'type' => 'tel', 'max' => 40, 'group' => 'Adresse'],
            ['name' => 'latitude', 'label' => 'Breitengrad', 'max' => 12, 'group' => 'Karte', 'help' => 'z. B. 51.6528 – leer lassen, wenn keine Karte gezeigt werden soll'],
            ['name' => 'longitude', 'label' => 'Längengrad', 'max' => 12, 'group' => 'Karte', 'help' => 'z. B. 7.0898'],
            ['name' => 'directions_html', 'label' => 'Anfahrtsbeschreibung', 'type' => 'html', 'group' => 'Karte'],
            ['name' => 'image_media_id', 'label' => 'Bild', 'type' => 'media', 'group' => 'Karte'],
        ];
        foreach (self::DAYS as $d => $label) {
            $f[] = ['name' => 'hours_' . $d, 'label' => $label, 'max' => 60, 'virtual' => true, 'group' => 'Öffnungszeiten',
                'help' => $d === 1 ? 'Format: 09:00-17:00, mehrere Zeiten mit Komma: 09:00-12:00, 13:00-16:00. Leer = geschlossen.' : null];
        }
        return array_map(fn($x) => array_filter($x, fn($v) => $v !== null), $f);
    }

    public function toForm(array $row): array
    {
        if (isset($row['id'])) {
            $by = [];
            foreach ($this->db->all('SELECT weekday, opens_at, closes_at FROM location_hours WHERE location_id = ? ORDER BY weekday, opens_at', [$row['id']]) as $h) {
                $by[$h['weekday']][] = substr($h['opens_at'], 0, 5) . '-' . substr($h['closes_at'], 0, 5);
            }
            foreach (self::DAYS as $d => $_) {
                $row['hours_' . $d] = implode(', ', $by[$d] ?? []);
            }
        }
        return $row;
    }

    /** @return list<array{0:string,1:string}>|null */
    private static function parseHours(string $s): ?array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $s))) as $part) {
            if (!preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)\s*[-–]\s*([01]?\d|2[0-3])[:.]([0-5]\d)$/', $part, $m)) {
                return null;
            }
            $a = sprintf('%02d:%02d:00', $m[1], $m[2]);
            $b = sprintf('%02d:%02d:00', $m[3], $m[4]);
            if ($b <= $a) {
                return null;
            }
            $out[] = [$a, $b];
        }
        return $out;
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        if (isset($values['slug']) && $this->isTaken('slug', $values['slug'], $id)) {
            $e['slug'] = 'Diese Adresse ist schon vergeben.';
        }
        foreach (['latitude' => 90, 'longitude' => 180] as $k => $max) {
            $v = $values[$k] ?? null;
            if ($v !== null && $v !== '' && (!is_numeric(str_replace(',', '.', (string) $v)) || abs((float) str_replace(',', '.', (string) $v)) > $max)) {
                $e[$k] = 'Bitte eine Dezimalzahl eingeben, z. B. 51.6528.';
            }
        }
        if (($values['latitude'] ?? null) === null xor ($values['longitude'] ?? null) === null) {
            $e['longitude'] = 'Bitte beide Koordinaten angeben oder beide leer lassen.';
        }
        foreach (self::DAYS as $d => $label) {
            if (self::parseHours((string) ($values['hours_' . $d] ?? '')) === null) {
                $e['hours_' . $d] = "$label: bitte im Format 09:00-17:00 angeben.";
            }
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        foreach (['latitude', 'longitude'] as $k) {
            $values[$k] = ($values[$k] ?? null) === null ? null : (float) str_replace(',', '.', (string) $values[$k]);
        }
        return $values;
    }

    public function afterSave(int $id, array $values, ?array $existing, AdminContext $ctx, Request $req): void
    {
        $this->db->run('DELETE FROM location_hours WHERE location_id = ?', [$id]);
        foreach (self::DAYS as $d => $_) {
            foreach (self::parseHours($req->input('hours_' . $d)) ?? [] as [$a, $b]) {
                $this->db->insert('location_hours', ['location_id' => $id, 'weekday' => $d, 'opens_at' => $a, 'closes_at' => $b]);
            }
        }
        if ((int) ($values['is_headquarters'] ?? 0) === 1) {
            $this->db->run('UPDATE locations SET is_headquarters = 0 WHERE id <> ?', [$id]);
        }
    }

    public function deleteBlocker(int $id): ?string
    {
        return $this->db->value('SELECT 1 FROM exam_sessions WHERE location_id = ? LIMIT 1', [$id]) !== null
            ? 'An diesem Standort gibt es Termine. Setzen Sie ihn stattdessen auf „nicht aktiv“.' : null;
    }

    public function publicUrl(array $row): ?string
    {
        return (int) $row['is_active'] === 1 ? '/standorte/' . $row['slug'] . '/' : null;
    }
}
