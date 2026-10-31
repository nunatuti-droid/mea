<?php
declare(strict_types=1);

namespace Mea\Admin\Crud;

use Mea\Admin\AdminContext;
use Mea\Admin\AdminKernel;
use Mea\Core\Database;
use Mea\Core\HtmlSanitizer;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\View;
use Mea\Module\Audit\AuditLog;

/** Liste, Anlegen, Bearbeiten und Löschen für jeden Resource-Bereich. */
final class CrudController
{
    public const PER_PAGE = 25;

    public function __construct(
        private Database $db,
        private View $view,
        private HtmlSanitizer $html,
        private AuditLog $audit,
    ) {}

    public function register(AdminKernel $k, Resource $r): void
    {
        $base = '/verwaltung/' . $r->key . '/';
        if (!$r->customIndex) {
            $k->get($base, $r->permission, fn(Request $q, AdminContext $c) => $this->index($r, $q, $c));
        }
        if ($r->creatable) {
            $k->get($base . 'neu/', $r->permission, fn(Request $q, AdminContext $c) => $this->form($r, $q, $c, null));
            $k->post($base . 'neu/', $r->permission, fn(Request $q, AdminContext $c) => $this->save($r, $q, $c, null));
        }
        $k->get($base . '{id:\d+}/', $r->permission, fn(Request $q, AdminContext $c, string $id) => $this->form($r, $q, $c, (int) $id));
        $k->post($base . '{id:\d+}/', $r->permission, fn(Request $q, AdminContext $c, string $id) => $this->save($r, $q, $c, (int) $id));
        if ($r->revisionEntity !== null) {
            $k->get($base . '{id:\d+}/vorschau/', $r->permission, fn(Request $q, AdminContext $c, string $id) => $this->preview($r, $q, $c, (int) $id));
        }
        if ($r->deletable()) {
            $k->post($base . '{id:\d+}/loeschen/', $r->permission, fn(Request $q, AdminContext $c, string $id) => $this->delete($r, $q, $c, (int) $id));
        }
    }

    public function index(Resource $r, Request $req, AdminContext $ctx): Response
    {
        $where = [];
        $params = [];
        $q = mb_substr($req->input('q'), 0, 100);
        if ($q !== '' && $r->searchColumns !== []) {
            $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $r->searchColumns)) . ')';
            foreach ($r->searchColumns as $_) {
                $params[] = '%' . addcslashes($q, '%_\\') . '%';
            }
        }
        [$fw, $fp] = $r->filterWhere($req);
        if ($fw !== '') {
            $where[] = $fw;
            $params = array_merge($params, $fp);
        }
        $sql = 'SELECT * FROM (' . $r->baseQuery() . ') x' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = (int) $this->db->value('SELECT COUNT(*) FROM (' . $sql . ') c', $params);
        $page = max(1, (int) $req->input('seite', '1'));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = $this->db->all($sql . ' ORDER BY ' . $r->orderBy . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE), $params);
        $filterValues = [];
        foreach ($r->filters() as $f) {
            $filterValues[$f['name']] = $req->input($f['name']);
        }
        return Response::html($this->view->render('admin/crud/index.twig', [
            'r' => $r, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'q' => $q,
            'filters' => $r->filters(), 'filter_values' => $filterValues,
            'query' => array_filter(['q' => $q] + $filterValues, fn($v) => $v !== ''),
        ]));
    }

    /** @param array<string,string> $errors @param array<string,mixed>|null $values */
    public function form(Resource $r, Request $req, AdminContext $ctx, ?int $id, array $errors = [], ?array $values = null, int $status = 200): Response
    {
        $row = null;
        if ($id !== null) {
            $row = $this->db->one("SELECT * FROM {$r->table} WHERE id = ?", [$id]);
            if ($row === null) {
                throw HttpException::notFound();
            }
        }
        $fields = $r->fields($row);
        $loadedRevision = null;
        if ($values === null && $id !== null && $r->revisionEntity !== null && ctype_digit($req->input('rev'))) {
            $loadedRevision = $this->db->one('SELECT r.*, u.name AS user_name FROM content_revisions r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = ? AND r.entity_type = ? AND r.entity_id = ?', [(int) $req->input('rev'), $r->revisionEntity, $id]);
            if ($loadedRevision !== null) {
                $values = Form::display($fields, $r->toForm(array_merge($row ?? [], (array) json_decode((string) $loadedRevision['snapshot_json'], true))));
            }
        }
        $display = $values ?? Form::display($fields, $r->toForm($row ?? ($id === null ? $r->prefill($req) : [])));
        $revisions = $id !== null && $r->revisionEntity !== null ? $this->db->all(
            'SELECT r.id, r.kind, r.note, r.created_at, u.name AS user_name FROM content_revisions r LEFT JOIN users u ON u.id = r.created_by
             WHERE r.entity_type = ? AND r.entity_id = ? ORDER BY r.id DESC LIMIT 25', [$r->revisionEntity, $id]) : [];
        $latestDraft = null;
        foreach ($revisions as $rev) {
            if ($rev['kind'] === 'published') {
                break;
            }
            $latestDraft = $latestDraft ?? $rev;
        }
        $groups = [];
        foreach ($fields as $f) {
            $groups[$f['group'] ?? 'Allgemein'][] = $f;
        }
        return Response::html($this->view->render('admin/crud/form.twig', [
            'r' => $r, 'row' => $row, 'id' => $id, 'groups' => $groups, 'values' => $display, 'errors' => $errors,
            'title' => $row ? $r->titleOf($row) : $r->singular . ' anlegen',
            'panels' => $id !== null ? $r->panels($id, $ctx) : [],
            'public_url' => $row ? $r->publicUrl($row) : null,
            'delete_blocker' => $id !== null && $r->deletable() ? $r->deleteBlocker($id) : null,
            'version' => $row['updated_at'] ?? '',
            'media_options' => $this->mediaOptions($fields),
            'revisions' => $revisions, 'latest_draft' => $latestDraft, 'loaded_revision' => $loadedRevision,
            'actions' => $row ? $r->actions($row, $ctx) : [],
        ]), $status);
    }

    public function save(Resource $r, Request $req, AdminContext $ctx, ?int $id): Response
    {
        $existing = $id !== null ? $this->db->one("SELECT * FROM {$r->table} WHERE id = ?", [$id]) : null;
        if ($id !== null && $existing === null) {
            throw HttpException::notFound();
        }
        $fields = $r->fields($existing);
        [$values, $errors, $raw] = Form::read($req, $fields, $this->html);
        $errors += $r->validate($values, $id);
        if ($existing !== null && $r->optimisticLock && isset($existing['updated_at']) && $req->input('_version') !== '' && $req->input('_version') !== (string) $existing['updated_at']) {
            $errors['_form'] = 'Dieser Eintrag wurde inzwischen von jemand anderem geändert. Bitte laden Sie die Seite neu und übernehmen Sie Ihre Änderungen erneut.';
        }
        if ($errors !== []) {
            $display = array_merge(Form::display($fields, $r->toForm($existing ?? [])), array_map(fn($v) => is_array($v) ? $v : (string) $v, $raw));
            return $this->form($r, $req, $ctx, $id, $errors, $display, 422);
        }
        $all = $values;
        $values = $r->beforeSave($values, $existing, $ctx);
        foreach ($fields as $f) {
            if (!empty($f['virtual'])) {
                unset($values[$f['name']]);
            }
        }
        if ($r->revisionEntity !== null && $id !== null && $req->input('_action') === 'draft') {
            $this->db->insert('content_revisions', [
                'entity_type' => $r->revisionEntity, 'entity_id' => $id, 'kind' => 'draft',
                'snapshot_json' => json_encode($values, JSON_UNESCAPED_UNICODE), 'created_by' => $ctx->id(),
            ]);
            $this->audit->record($r->entity . '.draft', 'Entwurf gespeichert: ' . $r->titleOf($values + $existing), $r->entity, $id);
            AdminKernel::flash('Entwurf gespeichert. Die Website zeigt weiterhin den veröffentlichten Stand.');
            $rev = (int) $this->db->value('SELECT MAX(id) FROM content_revisions WHERE entity_type = ? AND entity_id = ?', [$r->revisionEntity, $id]);
            return Response::redirect('/verwaltung/' . $r->key . '/' . $id . '/?rev=' . $rev, 303);
        }
        $newId = $this->db->transaction(function () use ($r, $values, $existing, $id, $ctx, $req): int {
            if ($id === null) {
                $newId = $this->db->insert($r->table, $values);
            } else {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($values)));
                $this->db->run("UPDATE {$r->table} SET $sets WHERE id = ?", [...array_values($values), $id]);
                $newId = $id;
            }
            $r->afterSave($newId, $values, $existing, $ctx, $req);
            if ($r->revisionEntity !== null) {
                $saved = $this->db->one("SELECT * FROM {$r->table} WHERE id = ?", [$newId]) ?? [];
                $snap = array_intersect_key($saved, $values);
                $this->db->insert('content_revisions', [
                    'entity_type' => $r->revisionEntity, 'entity_id' => $newId, 'kind' => 'published',
                    'snapshot_json' => json_encode($snap, JSON_UNESCAPED_UNICODE),
                    'note' => $req->input('rev') !== '' ? 'aus Version #' . (int) $req->input('rev') . ' wiederhergestellt' : null,
                    'created_by' => $ctx->id(),
                ]);
            }
            $label = $r->titleOf($values + ($existing ?? []) + ['id' => $newId]);
            $this->audit->record(
                $r->entity . ($id === null ? '.created' : '.updated'),
                $r->singular . ($id === null ? ' angelegt: ' : ' geändert: ') . $label,
                $r->entity, $newId,
                $id === null ? [] : ['changes' => AuditLog::diff($existing ?? [], $values)],
            );
            return $newId;
        });
        AdminKernel::flash($r->singular . ' gespeichert.');
        return Response::redirect('/verwaltung/' . $r->key . '/' . $newId . '/', 303);
    }

    public function preview(Resource $r, Request $req, AdminContext $ctx, int $id): Response
    {
        $row = $this->db->one("SELECT * FROM {$r->table} WHERE id = ?", [$id]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        if (ctype_digit($req->input('rev'))) {
            $rev = $this->db->value('SELECT snapshot_json FROM content_revisions WHERE id = ? AND entity_type = ? AND entity_id = ?', [(int) $req->input('rev'), $r->revisionEntity, $id]);
            if ($rev !== null) {
                $row = array_merge($row, (array) json_decode((string) $rev, true));
            }
        }
        $html = $r->preview($row);
        if ($html === null) {
            throw HttpException::notFound();
        }
        // Hinweisleiste, damit niemand die Vorschau mit der Live-Seite verwechselt
        $bar = '<div class="preview-bar" role="status">Vorschau – so sieht dieser Stand aus. Nicht veröffentlicht, solange Sie nicht speichern. <a href="/verwaltung/' . $r->key . '/' . $id . '/">Zurück zur Bearbeitung</a></div>';
        $html = preg_replace('/<body([^>]*)>/', '<body$1>' . $bar, $html, 1) ?? $html;
        return Response::html($html)->withHeader('X-Robots-Tag', 'noindex');
    }

    public function delete(Resource $r, Request $req, AdminContext $ctx, int $id): Response
    {
        $row = $this->db->one("SELECT * FROM {$r->table} WHERE id = ?", [$id]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        $blocker = $r->deleteBlocker($id);
        if ($blocker !== null) {
            AdminKernel::flash($blocker, 'error');
            return Response::redirect('/verwaltung/' . $r->key . '/' . $id . '/', 303);
        }
        $this->db->run("DELETE FROM {$r->table} WHERE id = ?", [$id]);
        $this->audit->record($r->entity . '.deleted', $r->singular . ' gelöscht: ' . $r->titleOf($row), $r->entity, $id);
        AdminKernel::flash($r->singular . ' gelöscht.');
        return Response::redirect('/verwaltung/' . $r->key . '/', 303);
    }

    /** @param list<array<string,mixed>> $fields @return list<array<string,mixed>> */
    private function mediaOptions(array $fields): array
    {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'media' || ($f['type'] ?? '') === 'html') {
                return $this->db->all("SELECT m.id, m.original_name, m.alt_text, m.mime,
                    (SELECT path FROM media_variants v WHERE v.media_id = m.id ORDER BY v.width LIMIT 1) AS thumb
                    FROM media m ORDER BY m.id DESC LIMIT 500");
            }
        }
        return [];
    }
}
