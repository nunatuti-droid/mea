<?php
declare(strict_types=1);

namespace Mea\Module\Locations;

use Mea\Core\Database;

final class LocationRepository
{
    private const DAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];

    public function __construct(private Database $db) {}

    /** @return list<array<string,mixed>> */
    public function active(): array
    {
        $rows = $this->db->all('SELECT * FROM locations WHERE is_active = 1 ORDER BY is_headquarters DESC, sort_order, id');
        return array_map(fn(array $r) => $this->withHours($r), $rows);
    }

    /** Zertifiziertes telc-Prüfungszentrum (derzeit Herne). @return array<string,mixed>|null */
    public function examCenter(): ?array
    {
        return $this->db->one('SELECT * FROM locations WHERE is_active = 1 AND is_exam_center = 1 ORDER BY sort_order, id LIMIT 1');
    }

    /** @return array<string,mixed>|null */
    public function bySlug(string $slug): ?array
    {
        $r = $this->db->one('SELECT * FROM locations WHERE slug = ? AND is_active = 1', [$slug]);
        return $r === null ? null : $this->withHours($r);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function withHours(array $r): array
    {
        $hours = $this->db->all(
            'SELECT weekday, opens_at, closes_at FROM location_hours WHERE location_id = ? ORDER BY weekday, opens_at',
            [$r['id']],
        );
        $r['hours'] = array_map(fn(array $h) => [
            'day' => self::DAYS[(int) $h['weekday']] ?? '',
            'weekday' => (int) $h['weekday'],
            'opens' => substr((string) $h['opens_at'], 0, 5),
            'closes' => substr((string) $h['closes_at'], 0, 5),
        ], $hours);
        $r['has_coordinates'] = $r['latitude'] !== null && $r['longitude'] !== null;
        $r['route_query'] = rawurlencode(sprintf('%s, %s %s', $r['street'], $r['postal_code'], $r['city']));
        return $r;
    }
}
