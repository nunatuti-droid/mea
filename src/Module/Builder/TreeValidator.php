<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Core\HtmlSanitizer;
use Mea\Module\Design\FontLibrary;

/**
 * Prüft einen Seitenaufbau vollständig auf dem Server: nur bekannte Bausteine, erlaubte Verschachtelung,
 * jedes Feld nach seinem Typ (Text ohne HTML, bereinigtes HTML, sichere Links, vorhandene Medien), Stil nach BlockStyle.
 * Ergebnis ist immer ein sauberer Baum; Probleme stehen in der Fehlerliste (Entwurf speicherbar, Veröffentlichen erst ohne Fehler).
 */
final class TreeValidator
{
    public const MAX_NODES = 1500;
    public const MAX_DEPTH = 8;
    public const MAX_RICH = 100000;

    private int $count = 0;
    /** @var array<string,bool> */
    private array $ids = [];
    /** @var array<string,int> */
    private array $singles = [];
    /** @var list<array{id:string, msg:string}> */
    private array $errors = [];
    /** @var list<int> */
    private array $mediaSeen = [];

    /**
     * @param \Closure(list<int>):list<int> $existingMedia gibt die vorhandenen Medien-IDs zurück
     * @param \Closure(int):bool $componentExists
     */
    public function __construct(
        private HtmlSanitizer $html,
        private FontLibrary $fonts,
        private \Closure $existingMedia,
        private \Closure $componentExists,
    ) {}

    /** @return array{0: list<array<string,mixed>>, 1: list<array{id:string, msg:string}>} */
    public function validate(mixed $tree): array
    {
        $this->count = 0;
        $this->ids = [];
        $this->singles = [];
        $this->errors = [];
        $this->mediaSeen = [];
        if (!is_array($tree) || !array_is_list($tree)) {
            return [[], [['id' => '', 'msg' => 'Der Seitenaufbau ist ungültig.']]];
        }
        $clean = $this->nodes($tree, null, 1);
        // Medien, die es nicht (mehr) gibt, werden entfernt
        if ($this->mediaSeen !== []) {
            $existing = array_flip(($this->existingMedia)(array_values(array_unique($this->mediaSeen))));
            $clean = $this->dropMissingMedia($clean, $existing);
        }
        return [$clean, $this->errors];
    }

    /** @param array<mixed> $nodes @return list<array<string,mixed>> */
    private function nodes(array $nodes, ?string $parent, int $depth): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            if (++$this->count > self::MAX_NODES) {
                $this->error('', 'Die Seite hat zu viele Elemente (höchstens ' . self::MAX_NODES . ').');
                break;
            }
            $type = is_string($n['type'] ?? null) ? $n['type'] : '';
            $def = BlockTypes::get($type);
            $id = is_string($n['id'] ?? null) && preg_match('/^[a-z0-9]{6,12}$/', $n['id']) && !isset($this->ids[$n['id']]) ? $n['id'] : self::newId();
            $this->ids[$id] = true;
            if ($def === null) {
                $this->error($id, 'Unbekannter Baustein „' . mb_substr($type, 0, 30) . '“ wurde entfernt.');
                continue;
            }
            if (!BlockTypes::allowedIn($type, $parent)) {
                $this->error($id, '„' . $def['label'] . '“ darf hier nicht stehen und wurde entfernt.');
                continue;
            }
            if (!empty($def['single']) && ($this->singles[$type] = ($this->singles[$type] ?? 0) + 1) > 1) {
                $this->error($id, '„' . $def['label'] . '“ darf nur einmal pro Seite vorkommen.');
                continue;
            }
            $node = ['id' => $id, 'type' => $type, 'f' => $this->fields($def['fields'], is_array($n['f'] ?? null) ? $n['f'] : [], $id)];
            $styleErrors = [];
            $style = BlockStyle::clean($n['s'] ?? null, $type, $this->fonts, $styleErrors);
            foreach ($styleErrors as $e) {
                $this->error($id, $def['label'] . ': ' . $e);
            }
            if ($style !== []) {
                $node['s'] = $style;
            }
            if (($n['off'] ?? false) === true) {
                $node['off'] = true;
            }
            if (is_string($n['name'] ?? null) && trim($n['name']) !== '') {
                $node['name'] = mb_substr(trim(strip_tags($n['name'])), 0, 60);
            }
            if (BlockTypes::acceptsChildren($type)) {
                if ($depth >= self::MAX_DEPTH) {
                    $this->error($id, 'Zu tief verschachtelt (höchstens ' . self::MAX_DEPTH . ' Ebenen).');
                    $node['c'] = [];
                } else {
                    $node['c'] = $this->nodes(is_array($n['c'] ?? null) ? array_values($n['c']) : [], $type, $depth + 1);
                }
            }
            $out[] = $node;
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $defs @param array<mixed> $in @return array<string,mixed> */
    private function fields(array $defs, array $in, string $id): array
    {
        $out = [];
        foreach ($defs as $f) {
            $raw = array_key_exists($f['name'], $in) ? $in[$f['name']] : $f['default'];
            $out[$f['name']] = $this->field($f, $raw, $id);
        }
        return $out;
    }

    /** @param array<string,mixed> $f */
    private function field(array $f, mixed $raw, string $id): mixed
    {
        $label = $f['label'];
        switch ($f['type']) {
            case 'text':
            case 'textarea':
                $s = is_scalar($raw) ? (string) $raw : '';
                $s = trim(strip_tags(str_replace("\r", '', $s)));
                if ($f['type'] === 'text') {
                    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
                }
                $max = (int) ($f['max'] ?? 200);
                if (mb_strlen($s) > $max) {
                    $this->error($id, "$label: höchstens $max Zeichen (gekürzt).");
                    $s = mb_substr($s, 0, $max);
                }
                if (($f['pattern'] ?? '') === 'anchor' && $s !== '' && !preg_match('/^[a-z][a-z0-9-]{0,39}$/', $s)) {
                    $this->error($id, "$label: nur Kleinbuchstaben, Ziffern und Bindestriche.");
                    $s = '';
                }
                if (!empty($f['required']) && $s === '') {
                    $this->error($id, "$label fehlt.");
                }
                return $s;
            case 'rich':
                $s = is_string($raw) ? $raw : '';
                if (strlen($s) > self::MAX_RICH) {
                    $this->error($id, "$label ist zu lang.");
                    $s = substr($s, 0, self::MAX_RICH);
                }
                return $s === '' ? '' : $this->html->clean($s);
            case 'url':
                $s = is_string($raw) ? trim($raw) : '';
                if ($s !== '' && !self::safeUrl($s)) {
                    $this->error($id, "$label: Bitte eine Adresse wie /kontakt/, https://…, mailto: oder tel: angeben.");
                    return '';
                }
                return $s;
            case 'media':
                if ($raw === null || $raw === '' || $raw === 0) {
                    return null;
                }
                if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                    return null;
                }
                $this->mediaSeen[] = (int) $raw;
                return (int) $raw;
            case 'media_list':
                $list = [];
                foreach (is_array($raw) ? $raw : [] as $m) {
                    if ((is_int($m) || (is_string($m) && ctype_digit($m))) && count($list) < ($f['max_items'] ?? 40)) {
                        $list[] = (int) $m;
                        $this->mediaSeen[] = (int) $m;
                    }
                }
                return $list;
            case 'select':
                $s = is_scalar($raw) ? (string) $raw : '';
                return array_key_exists($s, $f['options']) ? $s : $f['default'];
            case 'bool':
                return $raw === true || $raw === 1 || $raw === '1';
            case 'number':
                if (!is_numeric($raw)) {
                    return $f['default'];
                }
                return max($f['min'], min($f['max'], (int) $raw));
            case 'icon':
                $s = is_string($raw) ? $raw : '';
                return $s === '' || in_array($s, BlockTypes::ICONS, true) ? $s : '';
            case 'lines':
                $s = is_string($raw) ? str_replace("\r", '', strip_tags($raw)) : '';
                $lines = array_slice(array_map(fn($l) => mb_substr(trim($l), 0, 300), explode("\n", $s)), 0, (int) ($f['max'] ?? 60));
                return implode("\n", array_values(array_filter($lines, fn($l) => $l !== '')));
            case 'items':
                $items = [];
                foreach (is_array($raw) ? array_values($raw) : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (count($items) >= ($f['max_items'] ?? 24)) {
                        $this->error($id, "$label: höchstens " . $f['max_items'] . ' Einträge.');
                        break;
                    }
                    $items[] = $this->fields($f['fields'], $item, $id);
                }
                return $items;
            case 'component':
                $n = is_numeric($raw) ? (int) $raw : 0;
                if ($n <= 0 || !($this->componentExists)($n)) {
                    $this->error($id, 'Der globale Baustein existiert nicht mehr.');
                    return null;
                }
                return $n;
        }
        return null;
    }

    /** Erlaubt: /pfad, #anker, ?abfrage, http(s)://, mailto:, tel:. Kein javascript:, data:, //fremd. */
    public static function safeUrl(string $u): bool
    {
        if (strlen($u) > 500 || preg_match('/[\s<>"\'`\\\\]/', $u) || preg_match('/[\x00-\x1f]/', $u)) {
            return false;
        }
        if (str_starts_with($u, '//')) {
            return false;
        }
        if ($u[0] === '/' || $u[0] === '#' || $u[0] === '?') {
            return true;
        }
        if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/|$|\?|\#)#i', $u)) {
            return true;
        }
        return (bool) preg_match('/^(mailto:[^@\s]+@[^@\s]+|tel:\+?[0-9 ()\/-]{3,30})$/i', $u);
    }

    public static function newId(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 8);
    }

    /** @param list<array<string,mixed>> $nodes @param array<int,int> $existing @return list<array<string,mixed>> */
    private function dropMissingMedia(array $nodes, array $existing): array
    {
        foreach ($nodes as &$n) {
            foreach (BlockTypes::get($n['type'])['fields'] ?? [] as $f) {
                $n['f'][$f['name']] = $this->dropValue($f, $n['f'][$f['name']], $existing);
            }
            if (isset($n['c'])) {
                $n['c'] = $this->dropMissingMedia($n['c'], $existing);
            }
        }
        return $nodes;
    }

    /** @param array<string,mixed> $f @param array<int,int> $existing */
    private function dropValue(array $f, mixed $v, array $existing): mixed
    {
        return match ($f['type']) {
            'media' => $v !== null && isset($existing[$v]) ? $v : null,
            'media_list' => array_values(array_filter($v, fn($m) => isset($existing[$m]))),
            'items' => array_map(function ($item) use ($f, $existing) {
                foreach ($f['fields'] as $sub) {
                    $item[$sub['name']] = $this->dropValue($sub, $item[$sub['name']], $existing);
                }
                return $item;
            }, $v),
            default => $v,
        };
    }

    private function error(string $id, string $msg): void
    {
        if (count($this->errors) < 100) {
            $this->errors[] = ['id' => $id, 'msg' => $msg];
        }
    }

    /** Verwendet ein gespeicherter Seitenaufbau (JSON) dieses Medium? Prüft alle Bild-, Bildlisten- und Link-Felder. */
    public static function usesMedia(string $json, int $mediaId): bool
    {
        $tree = json_decode($json, true);
        if (!is_array($tree)) {
            return false;
        }
        $hit = false;
        $walk = function (array $nodes) use (&$walk, &$hit, $mediaId): void {
            foreach ($nodes as $n) {
                if ($hit || !is_array($n)) {
                    return;
                }
                $type = BlockTypes::get((string) ($n['type'] ?? ''));
                $f = is_array($n['f'] ?? null) ? $n['f'] : [];
                foreach ($type['fields'] ?? [] as $fd) {
                    if (self::fieldUses($fd, $f[$fd['name']] ?? null, $mediaId)) {
                        $hit = true;
                        return;
                    }
                }
                if (!empty($n['c']) && is_array($n['c'])) {
                    $walk($n['c']);
                }
            }
        };
        $walk($tree);
        return $hit;
    }

    /** @param array<string,mixed> $fd */
    private static function fieldUses(array $fd, mixed $v, int $mediaId): bool
    {
        return match ($fd['type']) {
            'media' => (int) $v === $mediaId,
            'media_list' => is_array($v) && in_array($mediaId, array_map('intval', $v), true),
            'url' => is_string($v) && (str_contains($v, '/media/dokumente/' . $mediaId . '-') || str_contains($v, '/media/' . $mediaId . '-')),
            'rich' => is_string($v) && (str_contains($v, '/media/' . $mediaId . '-') || str_contains($v, '/media/dokumente/' . $mediaId . '-')),
            'items' => is_array($v) && array_reduce($v, fn($c, $item) => $c || (is_array($item) && array_reduce($fd['fields'], fn($c2, $sub) => $c2 || self::fieldUses($sub, $item[$sub['name']] ?? null, $mediaId), false)), false),
            default => false,
        };
    }
}
