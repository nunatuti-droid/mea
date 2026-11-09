<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

use Mea\Core\Database;
use Psr\Log\LoggerInterface;

/**
 * Seiten und globale Bausteine des Seitenbaukastens: laden, Entwurf speichern (mit Schutz vor gleichzeitigen Änderungen),
 * veröffentlichen, Versionen, CSS-Datei der Elementstile.
 *
 * Öffentlich sichtbar ist nur blocks_json. Entwürfe liegen in draft_json und erscheinen nur in der Vorschau der Verwaltung.
 */
final class PageService
{
    public const REVISION_ENTITY = 'page_builder';
    public const COMPONENT_ENTITY = 'component';
    public const KEEP_REVISIONS = 60;
    public const CSS_ROUTE = '/seitenstil/';

    /** @var array<int,array<string,mixed>> */
    private array $components = [];

    /** @param \Closure():TreeValidator $validator */
    public function __construct(
        private Database $db,
        private \Closure $validator,
        private PageRenderer $renderer,
        private string $cssDir,
        private ?LoggerInterface $log = null,
    ) {}

    public function db(): Database
    {
        return $this->db;
    }

    public function validator(): TreeValidator
    {
        return ($this->validator)();
    }

    // ---------- Laden ----------

    /** @return array<string,mixed>|null */
    public function home(): ?array
    {
        return $this->db->one('SELECT * FROM pages WHERE is_home = 1');
    }

    /** @return array<string,mixed>|null */
    public function page(int $id): ?array
    {
        return $this->db->one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null veröffentlichte Seite */
    public function published(string $slug): ?array
    {
        return $this->db->one("SELECT * FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
    }

    /** @param array<string,mixed> $page @return list<array<string,mixed>> */
    public static function tree(array $page, bool $draft = false): array
    {
        $json = $draft && $page['draft_json'] !== null ? $page['draft_json'] : $page['blocks_json'];
        $t = $json !== null ? json_decode((string) $json, true) : null;
        return is_array($t) ? $t : [];
    }

    public static function url(array $page): string
    {
        return (int) ($page['is_home'] ?? 0) === 1 ? '/' : '/' . $page['slug'] . '/';
    }

    /** Globaler Baustein für die Ausgabe. @return array{name:string, tree:list<array<string,mixed>>}|null */
    public function component(int $id, bool $draft = false): ?array
    {
        $key = $id * 2 + ($draft ? 1 : 0);
        if (!array_key_exists($key, $this->components)) {
            $row = $this->db->one('SELECT id, name, tree_json, draft_json FROM builder_components WHERE id = ?', [$id]);
            $json = $row === null ? null : ($draft && $row['draft_json'] !== null ? $row['draft_json'] : $row['tree_json']);
            $tree = $json !== null ? json_decode((string) $json, true) : null;
            $this->components[$key] = $row === null ? null : ['name' => (string) $row['name'], 'tree' => is_array($tree) ? $tree : []];
        }
        return $this->components[$key];
    }

    /** @return list<array<string,mixed>> */
    public function components(): array
    {
        return $this->db->all('SELECT c.id, c.name, c.description, c.is_locked, c.updated_at, c.draft_json IS NOT NULL AS has_draft, u.name AS user_name
            FROM builder_components c LEFT JOIN users u ON u.id = c.updated_by ORDER BY c.name');
    }

    /** Seiten, die einen globalen Baustein verwenden. @return list<array{id:int,title:string}> */
    public function componentUsage(int $id): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, title, blocks_json, draft_json FROM pages') as $p) {
            foreach ([$p['blocks_json'], $p['draft_json']] as $json) {
                if ($json !== null && self::usesComponent((array) json_decode((string) $json, true), $id)) {
                    $out[] = ['id' => (int) $p['id'], 'title' => (string) $p['title']];
                    break;
                }
            }
        }
        return $out;
    }

    /** @param array<mixed> $nodes */
    private static function usesComponent(array $nodes, int $id): bool
    {
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            if (($n['type'] ?? '') === 'component' && (int) ($n['f']['ref'] ?? 0) === $id) {
                return true;
            }
            if (!empty($n['c']) && is_array($n['c']) && self::usesComponent($n['c'], $id)) {
                return true;
            }
        }
        return false;
    }

    // ---------- Speichern ----------

    /**
     * Entwurf speichern. $baseRev ist der Stand, auf dem der Editor aufbaut; hat jemand anderes inzwischen gespeichert, wird nichts überschrieben.
     * @return array{ok:bool, conflict?:bool, rev?:int, tree?:list<array<string,mixed>>, errors:list<array{id:string,msg:string}>, by?:string}
     */
    public function saveDraft(int $pageId, mixed $tree, int $baseRev, ?int $userId, string $table = 'pages'): array
    {
        [$clean, $errors] = $this->validator()->validate($tree);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 4_000_000) {
            return ['ok' => false, 'errors' => [['id' => '', 'msg' => 'Die Seite ist zu groß.']]];
        }
        $sql = $table === 'pages'
            ? 'UPDATE pages SET draft_json = ?, draft_rev = draft_rev + 1, draft_by = ?, draft_updated_at = UTC_TIMESTAMP() WHERE id = ? AND draft_rev = ?'
            : 'UPDATE builder_components SET draft_json = ?, draft_rev = draft_rev + 1, updated_by = ? WHERE id = ? AND draft_rev = ?';
        if ($this->db->run($sql, [$json, $userId, $pageId, $baseRev]) === 0) {
            $row = $table === 'pages'
                ? $this->db->one('SELECT p.draft_rev, u.name FROM pages p LEFT JOIN users u ON u.id = p.draft_by WHERE p.id = ?', [$pageId])
                : $this->db->one('SELECT c.draft_rev, u.name FROM builder_components c LEFT JOIN users u ON u.id = c.updated_by WHERE c.id = ?', [$pageId]);
            if ($row === null) {
                return ['ok' => false, 'errors' => [['id' => '', 'msg' => 'Nicht gefunden.']]];
            }
            return ['ok' => false, 'conflict' => true, 'rev' => (int) $row['draft_rev'], 'by' => (string) ($row['name'] ?? 'jemand anderes'), 'errors' => []];
        }
        $this->revision($table === 'pages' ? self::REVISION_ENTITY : self::COMPONENT_ENTITY, $pageId, 'draft', $clean, $userId);
        return ['ok' => true, 'rev' => $baseRev + 1, 'tree' => $clean, 'errors' => $errors];
    }

    /**
     * Gespeicherten Entwurf veröffentlichen. Nur ohne Prüffehler.
     * @return array{ok:bool, errors:list<array{id:string,msg:string}>}
     */
    public function publish(int $pageId, ?int $userId): array
    {
        $page = $this->page($pageId);
        if ($page === null) {
            return ['ok' => false, 'errors' => [['id' => '', 'msg' => 'Seite nicht gefunden.']]];
        }
        [$clean, $errors] = $this->validator()->validate(self::tree($page, true));
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->transaction(function () use ($pageId, $json, $page): void {
            $this->db->run("UPDATE pages SET blocks_json = ?, draft_json = NULL, status = 'published', published_at = COALESCE(published_at, UTC_TIMESTAMP()) WHERE id = ?", [$json, $pageId]);
        });
        $this->revision(self::REVISION_ENTITY, $pageId, 'published', $clean, $userId, (string) $page['title']);
        $this->writeCss($pageId);
        return ['ok' => true, 'errors' => []];
    }

    /** Entwurf verwerfen (zurück zum veröffentlichten Stand). */
    public function discardDraft(int $pageId, string $table = 'pages'): void
    {
        $this->db->run($table === 'pages' ? 'UPDATE pages SET draft_json = NULL, draft_rev = draft_rev + 1 WHERE id = ?'
            : 'UPDATE builder_components SET draft_json = NULL, draft_rev = draft_rev + 1 WHERE id = ?', [$pageId]);
    }

    /** @return array{ok:bool, errors:list<array{id:string,msg:string}>} */
    public function publishComponent(int $id, ?int $userId): array
    {
        $row = $this->db->one('SELECT * FROM builder_components WHERE id = ?', [$id]);
        if ($row === null) {
            return ['ok' => false, 'errors' => [['id' => '', 'msg' => 'Baustein nicht gefunden.']]];
        }
        $tree = json_decode((string) ($row['draft_json'] ?? $row['tree_json']), true);
        [$clean, $errors] = $this->validator()->validate(is_array($tree) ? $tree : []);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        if (self::usesAnyComponent($clean)) {
            return ['ok' => false, 'errors' => [['id' => '', 'msg' => 'Ein globaler Baustein darf keine weiteren globalen Bausteine enthalten.']]];
        }
        $this->db->run('UPDATE builder_components SET tree_json = ?, draft_json = NULL, updated_by = ? WHERE id = ?', [json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $userId, $id]);
        $this->components = [];
        $this->revision(self::COMPONENT_ENTITY, $id, 'published', $clean, $userId, (string) $row['name']);
        // Seiten, die den Baustein verwenden, brauchen dessen Stile
        foreach ($this->componentUsage($id) as $p) {
            $this->writeCss($p['id']);
        }
        return ['ok' => true, 'errors' => []];
    }

    /** @param list<array<string,mixed>> $nodes */
    private static function usesAnyComponent(array $nodes): bool
    {
        foreach ($nodes as $n) {
            if ($n['type'] === 'component' || (!empty($n['c']) && self::usesAnyComponent($n['c']))) {
                return true;
            }
        }
        return false;
    }

    /** Neuen globalen Baustein aus einem Ausschnitt anlegen. @param list<array<string,mixed>> $nodes @return array{ok:bool, id?:int, errors:list<array{id:string,msg:string}>} */
    public function createComponent(string $name, mixed $nodes, ?int $userId): array
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 80);
        if ($name === '') {
            return ['ok' => false, 'errors' => [['id' => '', 'msg' => 'Bitte einen Namen angeben.']]];
        }
        [$clean, $errors] = $this->validator()->validate($nodes);
        if ($clean === [] || self::usesAnyComponent($clean)) {
            return ['ok' => false, 'errors' => $errors ?: [['id' => '', 'msg' => 'Ein globaler Baustein darf keine weiteren globalen Bausteine enthalten.']]];
        }
        // Neue IDs, damit Stile nicht mit der Herkunftsseite kollidieren
        $clean = self::reId($clean);
        $id = $this->db->insert('builder_components', ['name' => $name, 'tree_json' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_by' => $userId, 'updated_by' => $userId]);
        return ['ok' => true, 'id' => $id, 'errors' => []];
    }

    /** @param list<array<string,mixed>> $nodes @return list<array<string,mixed>> */
    public static function reId(array $nodes): array
    {
        foreach ($nodes as &$n) {
            $n['id'] = TreeValidator::newId();
            if (!empty($n['c'])) {
                $n['c'] = self::reId($n['c']);
            }
        }
        return $nodes;
    }

    // ---------- Versionen ----------

    /** @param list<array<string,mixed>> $tree */
    private function revision(string $entity, int $id, string $kind, array $tree, ?int $userId, string $note = ''): void
    {
        $this->db->insert('content_revisions', ['entity_type' => $entity, 'entity_id' => $id, 'kind' => $kind,
            'snapshot_json' => json_encode(['tree' => $tree], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'note' => $note !== '' ? mb_substr($note, 0, 255) : null, 'created_by' => $userId]);
        $keep = $this->db->value('SELECT id FROM content_revisions WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP_REVISIONS, [$entity, $id]);
        if ($keep !== null) {
            $this->db->run('DELETE FROM content_revisions WHERE entity_type = ? AND entity_id = ? AND id <= ?', [$entity, $id, $keep]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $id, string $entity = self::REVISION_ENTITY): array
    {
        return $this->db->all('SELECT r.id, r.kind, r.created_at, u.name AS user_name FROM content_revisions r LEFT JOIN users u ON u.id = r.created_by
            WHERE r.entity_type = ? AND r.entity_id = ? ORDER BY r.id DESC LIMIT 60', [$entity, $id]);
    }

    /** @return list<array<string,mixed>>|null */
    public function revisionTree(int $id, int $revId, string $entity = self::REVISION_ENTITY): ?array
    {
        $json = $this->db->value('SELECT snapshot_json FROM content_revisions WHERE id = ? AND entity_type = ? AND entity_id = ?', [$revId, $entity, $id]);
        $data = $json !== null ? json_decode((string) $json, true) : null;
        return is_array($data['tree'] ?? null) ? $data['tree'] : null;
    }

    // ---------- Seiten verwalten ----------

    /** Kopie einer Seite als Entwurf. */
    public function duplicate(int $id): ?int
    {
        $p = $this->page($id);
        if ($p === null) {
            return null;
        }
        $base = (int) $p['is_home'] === 1 ? 'startseite-kopie' : $p['slug'] . '-kopie';
        $slug = $base;
        for ($i = 2; $this->db->value('SELECT 1 FROM pages WHERE slug = ?', [$slug]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }
        $tree = self::reId(self::tree($p, true));
        return $this->db->insert('pages', [
            'slug' => $slug, 'title' => mb_substr($p['title'] . ' (Kopie)', 0, 200), 'intro' => $p['intro'], 'template' => 'default', 'status' => 'draft', 'is_system' => 0,
            'blocks_json' => json_encode([], JSON_THROW_ON_ERROR), 'draft_json' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'seo_title' => null, 'seo_description' => $p['seo_description'], 'hero_media_id' => $p['hero_media_id'],
        ]);
    }

    // ---------- CSS der Elementstile ----------

    /** CSS einer Seite inklusive der verwendeten globalen Bausteine. @param list<array<string,mixed>> $tree */
    public function cssFor(array $tree, bool $draft = false): string
    {
        $css = $this->renderer->css($tree);
        $seen = [];
        $walk = function (array $nodes) use (&$walk, &$css, &$seen, $draft): void {
            foreach ($nodes as $n) {
                if (($n['type'] ?? '') === 'component' && !isset($seen[(int) ($n['f']['ref'] ?? 0)])) {
                    $seen[$ref = (int) ($n['f']['ref'] ?? 0)] = true;
                    $c = $this->component($ref, $draft);
                    if ($c !== null) {
                        $css .= $this->renderer->css($c['tree']);
                    }
                }
                if (!empty($n['c'])) {
                    $walk($n['c']);
                }
            }
        };
        $walk($tree);
        return $css;
    }

    /** Erzeugt die CSS-Datei der veröffentlichten Seite (Name mit Prüfsumme, damit Browser-Caches sofort wechseln). */
    public function writeCss(int $pageId): ?string
    {
        $page = $this->page($pageId);
        if ($page === null) {
            return null;
        }
        $css = $this->cssFor(self::tree($page));
        if ($css === '') {
            $this->db->run('UPDATE pages SET css_hash = ? WHERE id = ?', ['', $pageId]);
            return '';
        }
        $hash = substr(hash('sha256', $css), 0, 16);
        $file = $this->cssDir . '/seite-' . $pageId . '-' . $hash . '.css';
        if (!is_file($file) && (is_dir($this->cssDir) || @mkdir($this->cssDir, 0755, true)) && is_writable($this->cssDir)) {
            $tmp = $this->cssDir . '/.tmp-' . bin2hex(random_bytes(6));
            if (@file_put_contents($tmp, "/* Seitenbaukasten: Stile der Seite $pageId. Nicht von Hand bearbeiten. */\n" . $css) !== false && @rename($tmp, $file)) {
                foreach (glob($this->cssDir . '/seite-' . $pageId . '-*.css') ?: [] as $old) {
                    if ($old !== $file && filemtime($old) < time() - 3600) {
                        @unlink($old);
                    }
                }
            } else {
                @unlink($tmp);
                $this->log?->warning('Seitenbaukasten: CSS-Datei nicht beschreibbar, Auslieferung über ' . self::CSS_ROUTE, ['dir' => $this->cssDir]);
            }
        }
        $this->db->run('UPDATE pages SET css_hash = ? WHERE id = ?', [$hash, $pageId]);
        return $hash;
    }

    /** Adresse des Stylesheets einer veröffentlichten Seite oder null, wenn sie keine eigenen Stile hat. @param array<string,mixed> $page */
    public function cssUrl(array $page, string $publicBase): ?string
    {
        $hash = $page['css_hash'];
        if ($hash === null) {
            $hash = $this->writeCss((int) $page['id']);
        }
        if ($hash === null || $hash === '') {
            return null;
        }
        $name = 'seite-' . $page['id'] . '-' . $hash . '.css';
        if (!is_file($this->cssDir . '/' . $name)) {
            $hash = $this->writeCss((int) $page['id']);
            $name = 'seite-' . $page['id'] . '-' . $hash . '.css';
        }
        return is_file($this->cssDir . '/' . $name) ? $publicBase . '/' . $name : self::CSS_ROUTE . $page['id'] . '-' . $hash . '.css';
    }

    /** Ausweg ohne beschreibbaren Ordner. */
    public function cssByHash(int $pageId, string $hash): ?string
    {
        $page = $this->page($pageId);
        if ($page === null || $page['css_hash'] !== $hash) {
            return null;
        }
        return $this->cssFor(self::tree($page));
    }

    /** Alle Seitenstile neu erzeugen (z. B. nach Änderung der Umbruchpunkte im Designsystem). */
    public function rebuildAllCss(): void
    {
        foreach ($this->db->all('SELECT id FROM pages') as $p) {
            $this->writeCss((int) $p['id']);
        }
    }
}
