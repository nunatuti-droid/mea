<?php
declare(strict_types=1);

namespace Mea\Module\Content;

use Mea\Core\Database;

/** Lesender Zugriff auf veröffentlichte Inhalte für die öffentliche Website. */
final class ContentRepository
{
    public function __construct(private Database $db) {}

    /** @return array<string,mixed>|null */
    public function publishedPage(string $slug): ?array
    {
        return $this->db->one("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
    }

    /** @return list<array<string,mixed>> */
    public function publishedPages(): array
    {
        return $this->db->all("SELECT slug, updated_at FROM pages WHERE status = 'published'");
    }

    /** @return list<array<string,mixed>> Hauptleistungen (ohne Unterleistungen) */
    public function topServices(): array
    {
        return $this->decode($this->db->all(
            "SELECT * FROM services WHERE parent_id IS NULL AND status = 'published' ORDER BY sort_order, id",
        ));
    }

    /** @return array<string,mixed>|null */
    public function publishedService(string $slug, ?int $parentId = null): ?array
    {
        $row = $parentId === null
            ? $this->db->one("SELECT * FROM services WHERE slug = ? AND parent_id IS NULL AND status = 'published'", [$slug])
            : $this->db->one("SELECT * FROM services WHERE slug = ? AND parent_id = ? AND status = 'published'", [$slug, $parentId]);
        return $row === null ? null : $this->decode([$row])[0];
    }

    /** @return list<array<string,mixed>> */
    public function childServices(int $parentId): array
    {
        return $this->decode($this->db->all(
            "SELECT * FROM services WHERE parent_id = ? AND status = 'published' ORDER BY sort_order, id",
            [$parentId],
        ));
    }

    /** @return list<array<string,mixed>> */
    public function allPublishedServices(): array
    {
        return $this->db->all(
            "SELECT s.slug, s.updated_at, p.slug AS parent_slug FROM services s
             LEFT JOIN services p ON p.id = s.parent_id
             WHERE s.status = 'published' AND (p.id IS NULL OR p.status = 'published')",
        );
    }

    /** @return list<array<string,mixed>> */
    public function menu(string $key): array
    {
        $rows = $this->db->all(
            'SELECT mi.* FROM menu_items mi JOIN menus m ON m.id = mi.menu_id
             WHERE m.menu_key = ? ORDER BY mi.sort_order, mi.id',
            [$key],
        );
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $tree = [];
        foreach ($byParent[0] ?? [] as $r) {
            $r['children'] = $byParent[(int) $r['id']] ?? [];
            $tree[] = $r;
        }
        return $tree;
    }

    /** @return list<array<string,mixed>> */
    public function faqsFor(string $type, ?int $id = null): array
    {
        return $this->db->all(
            'SELECT f.* FROM faqs f JOIN faq_assignments a ON a.faq_id = f.id
             WHERE f.is_active = 1 AND a.target_type = ? AND (a.target_id <=> ?)
             ORDER BY f.sort_order, f.id',
            [$type, $id],
        );
    }

    /** @return list<array<string,mixed>> */
    public function reviews(bool $heroOnly = false): array
    {
        return $this->db->all(
            'SELECT * FROM reviews WHERE is_active = 1' . ($heroOnly ? ' AND show_in_hero = 1' : '') .
            ' ORDER BY sort_order, id',
        );
    }

    /** @return list<array<string,mixed>> sichtbare Google-Bewertungen, neueste zuerst */
    public function googleReviews(int $limit = 3): array
    {
        return $this->db->all(
            'SELECT * FROM google_reviews WHERE is_active = 1 ORDER BY sort_order, published_at IS NULL, published_at DESC, id DESC LIMIT ' . max(1, $limit),
        );
    }

    /** @return array{count:int, average:float|null} nur echte, gepflegte Sternebewertungen */
    public function ratingSummary(): array
    {
        $r = $this->db->one('SELECT COUNT(rating) AS c, AVG(rating) AS a FROM reviews WHERE is_active = 1 AND rating IS NOT NULL');
        return ['count' => (int) ($r['c'] ?? 0), 'average' => $r['a'] === null ? null : round((float) $r['a'], 1)];
    }

    /** @return array<string,mixed>|null */
    public function redirectFor(string $path): ?array
    {
        $row = $this->db->one('SELECT * FROM redirects WHERE source_path = ?', [$path]);
        if ($row === null) {
            // Platzhalter am Ende („/alter-bereich/*“) gilt für alle Unteradressen; die längste Regel gewinnt
            foreach ($this->db->all("SELECT * FROM redirects WHERE source_path LIKE '%*' ORDER BY CHAR_LENGTH(source_path) DESC") as $r) {
                if (str_starts_with($path, substr((string) $r['source_path'], 0, -1))) {
                    $row = $r;
                    break;
                }
            }
        }
        if ($row !== null) {
            $this->db->run('UPDATE redirects SET hits = hits + 1, last_hit_at = UTC_TIMESTAMP() WHERE id = ?', [$row['id']]);
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    public function serviceById(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM services WHERE id = ?', [$id]);
        return $row === null ? null : $this->decode([$row])[0];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function decodeService(array $row): array
    {
        return $this->decode([$row])[0];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function decode(array $rows): array
    {
        foreach ($rows as &$r) {
            $r['facts'] = isset($r['facts_json']) ? (json_decode((string) $r['facts_json'], true) ?: []) : [];
        }
        return $rows;
    }
}
