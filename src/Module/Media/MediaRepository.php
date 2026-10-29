<?php
declare(strict_types=1);

namespace Mea\Module\Media;

use Mea\Core\Database;

/** Liefert Bilder mit ihren Varianten und erzeugt das passende HTML. */
final class MediaRepository
{
    /** @var array<int,array<string,mixed>|null> */
    private array $cache = [];

    public function __construct(private Database $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if (!array_key_exists($id, $this->cache)) {
            $row = $this->db->one('SELECT * FROM media WHERE id = ?', [$id]);
            if ($row !== null) {
                $row['variants'] = $this->db->all(
                    'SELECT width, height, path FROM media_variants WHERE media_id = ? ORDER BY width',
                    [$id],
                );
            }
            $this->cache[$id] = $row;
        }
        return $this->cache[$id];
    }

    /** @return array<string,mixed>|null */
    private function resolve(mixed $m): ?array
    {
        if (is_array($m)) {
            return isset($m['variants']) ? $m : (isset($m['id']) ? $this->find((int) $m['id']) : null);
        }
        if (is_int($m) || (is_string($m) && ctype_digit($m))) {
            return $this->find((int) $m);
        }
        return null;
    }

    public function url(mixed $m, int $width = 1600): string
    {
        $row = $this->resolve($m);
        if ($row === null || $row['variants'] === []) {
            return '';
        }
        $best = $row['variants'][0];
        foreach ($row['variants'] as $v) {
            $best = $v;
            if ((int) $v['width'] >= $width) {
                break;
            }
        }
        return '/media/' . $best['path'];
    }

    /** @param array<string,string> $attr */
    public function render(mixed $m, string $sizes = '100vw', array $attr = []): string
    {
        $row = $this->resolve($m);
        if ($row === null || $row['variants'] === []) {
            return '';
        }
        $srcset = [];
        foreach ($row['variants'] as $v) {
            $srcset[] = '/media/' . $v['path'] . ' ' . $v['width'] . 'w';
        }
        $largest = end($row['variants']);
        $attr += ['loading' => 'lazy', 'decoding' => 'async'];
        $alt = $attr['alt'] ?? (string) $row['alt_text'];
        unset($attr['alt']);
        $html = sprintf(
            '<img src="%s" srcset="%s" sizes="%s" width="%d" height="%d" alt="%s"',
            htmlspecialchars('/media/' . $largest['path'], ENT_QUOTES),
            htmlspecialchars(implode(', ', $srcset), ENT_QUOTES),
            htmlspecialchars($sizes, ENT_QUOTES),
            (int) $row['width'],
            (int) $row['height'],
            htmlspecialchars($alt, ENT_QUOTES),
        );
        foreach ($attr as $k => $v) {
            if (preg_match('/^[a-z-]+$/', (string) $k)) {
                $html .= sprintf(' %s="%s"', $k, htmlspecialchars((string) $v, ENT_QUOTES));
            }
        }
        return $html . '>';
    }
}
