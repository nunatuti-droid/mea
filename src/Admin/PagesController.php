<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Builder\BlockStyle;
use Mea\Module\Builder\BlockTypes;
use Mea\Module\Builder\PageService;

/**
 * Seitenverwaltung und visueller Seitenbaukasten.
 *
 * Seiten (Tabelle pages, inklusive Startseite) und globale Bausteine (builder_components) nutzen denselben Editor.
 * Rechte: „content“ zum Bearbeiten und Speichern von Entwürfen, „publish“ zum Veröffentlichen und für globale Bausteine.
 * Jede schreibende Aktion prüft Recht und CSRF-Token serverseitig (AdminKernel) und den Seitenaufbau vollständig (TreeValidator).
 */
final class PagesController
{
    private const PAGES = '/verwaltung/seiten/';
    private const COMPONENTS = '/verwaltung/bausteine/';

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get(self::PAGES, 'content', $this->index(...));
        $k->post(self::PAGES . '{id:\d+}/duplizieren/', 'content', $this->duplicate(...));
        foreach (['page' => self::PAGES, 'component' => self::COMPONENTS] as $kind => $base) {
            $writePerm = $kind === 'page' ? 'content' : 'publish';
            $k->get($base . '{id:\d+}/editor/', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->editor($r, $c, $kind, (int) $id));
            $k->get($base . '{id:\d+}/leinwand/', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->canvas($r, $c, $kind, (int) $id));
            $k->get($base . '{id:\d+}/entwurf-ansehen/', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->previewDraft($r, $c, $kind, (int) $id));
            $k->get($base . '{id:\d+}/entwurf.css', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->draftCss($r, $c, $kind, (int) $id));
            $k->post($base . '{id:\d+}/darstellen/', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->render($r, $c, $kind, (int) $id));
            $k->post($base . '{id:\d+}/speichern/', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->save($r, $c, $kind, (int) $id, false));
            $k->post($base . '{id:\d+}/veroeffentlichen/', 'publish', fn(Request $r, AdminContext $c, string $id) => $this->save($r, $c, $kind, (int) $id, true));
            $k->post($base . '{id:\d+}/verwerfen/', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->discard($r, $c, $kind, (int) $id));
            $k->get($base . '{id:\d+}/versionen.json', $writePerm, fn(Request $r, AdminContext $c, string $id) => $this->versions($r, $c, $kind, (int) $id));
            $k->get($base . '{id:\d+}/versionen/{rev:\d+}.json', $writePerm, fn(Request $r, AdminContext $c, string $id, string $rev) => $this->version($r, $c, $kind, (int) $id, (int) $rev));
        }
        $k->post(self::PAGES . '{id:\d+}/einstellungen/', 'content', $this->settings(...));
        $k->get(self::COMPONENTS, 'content', $this->components(...));
        $k->get(self::COMPONENTS . 'liste.json', 'content', $this->componentsJson(...));
        $k->post(self::COMPONENTS . 'neu/', 'publish', $this->createComponent(...));
        $k->post(self::COMPONENTS . '{id:\d+}/umbenennen/', 'publish', $this->renameComponent(...));
        $k->post(self::COMPONENTS . '{id:\d+}/loeschen/', 'publish', $this->deleteComponent(...));
    }

    private function pages(): PageService
    {
        return $this->app->pages();
    }

    // ---------- Übersicht ----------

    public function index(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        $pages = $db->all('SELECT p.id, p.slug, p.title, p.status, p.is_system, p.is_home, p.updated_at, p.draft_json IS NOT NULL AS has_draft,
            p.draft_updated_at, p.seo_title, p.seo_description, u.name AS draft_user
            FROM pages p LEFT JOIN users u ON u.id = p.draft_by ORDER BY p.is_home IS NULL, p.is_system DESC, p.title');
        $special = [
            ['label' => 'Leistungen', 'count' => (int) $db->value("SELECT COUNT(*) FROM services"), 'url' => '/verwaltung/leistungen/', 'icon' => 'briefcase',
                'note' => 'Eigene Seite je Leistung (z. B. /sprachkurse/) mit Feldern für Text, Bilder, Fakten und FAQ. Vorschau im Formular.'],
            ['label' => 'Prüfungen', 'count' => (int) $db->value("SELECT COUNT(*) FROM exam_types"), 'url' => '/verwaltung/pruefungen/', 'icon' => 'award',
                'note' => 'Eine Seite je Prüfungsart (/pruefungen/…/) mit Terminen, Preisen und Anmeldung.'],
            ['label' => 'Standorte', 'count' => (int) $db->value('SELECT COUNT(*) FROM locations'), 'url' => '/verwaltung/standorte/', 'icon' => 'map-pin',
                'note' => 'Eine Seite je Standort (/standorte/…/) mit Adresse, Öffnungszeiten und Anfahrt.'],
        ];
        $global = [
            ['label' => 'Navigation (Hauptmenü, Fußbereich, Rechtliches)', 'url' => '/verwaltung/menues/', 'icon' => 'list', 'perm' => 'content'],
            ['label' => 'Kopf- und Fußbereich, Texte der Vorlagen', 'url' => '/verwaltung/vorlagentexte/', 'icon' => 'panel-top', 'perm' => 'publish'],
            ['label' => 'Globale Bausteine', 'url' => self::COMPONENTS, 'icon' => 'layers', 'perm' => 'content'],
            ['label' => 'Firmendaten, Logo, Kontaktdaten', 'url' => '/verwaltung/einstellungen/', 'icon' => 'settings', 'perm' => 'settings'],
            ['label' => 'Designsystem (Schriften, Farben, Abstände)', 'url' => '/verwaltung/designsystem/', 'icon' => 'palette', 'perm' => 'design'],
        ];
        return Response::html($this->app->view->render('admin/builder/pages.twig', [
            'title' => 'Seiten', 'pages' => $pages, 'special' => $special, 'global' => $global,
        ]));
    }

    public function duplicate(Request $req, AdminContext $ctx, string $id): Response
    {
        $new = $this->pages()->duplicate((int) $id);
        if ($new === null) {
            throw HttpException::notFound();
        }
        $this->app->audit->record('page.duplicated', 'Seite kopiert (Entwurf)', 'page', $new, ['von' => (int) $id]);
        AdminKernel::flash('Kopie als Entwurf angelegt. Adresse und Titel bitte anpassen.');
        return Response::redirect(self::PAGES . $new . '/', 303);
    }

    // ---------- Editor ----------

    /** @return array<string,mixed> Seite oder Baustein */
    private function load(string $kind, int $id): array
    {
        $row = $kind === 'page' ? $this->pages()->page($id) : $this->app->db->one('SELECT * FROM builder_components WHERE id = ?', [$id]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return list<array<string,mixed>> */
    private function tree(string $kind, array $row): array
    {
        if ($kind === 'page') {
            return PageService::tree($row, true);
        }
        $t = json_decode((string) ($row['draft_json'] ?? $row['tree_json']), true);
        return is_array($t) ? $t : [];
    }

    private function base(string $kind, int $id): string
    {
        return ($kind === 'page' ? self::PAGES : self::COMPONENTS) . $id . '/';
    }

    public function editor(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $row = $this->load($kind, $id);
        $design = $this->app->design();
        $values = $design->values();
        $tokens = [];
        foreach (BlockStyle::tokens() as $key => [$label, $var]) {
            $tokens[] = ['key' => $key, 'label' => $label, 'value' => $values[array_search($var, array_column(\Mea\Module\Design\DesignSchema::fields(), 'var', 'key'), true)] ?? ''];
        }
        $fonts = [['id' => 'heading', 'label' => 'Wie Überschriften (Designsystem)'], ['id' => 'body', 'label' => 'Wie Fließtext (Designsystem)'], ['id' => 'ui', 'label' => 'Wie Buttons (Designsystem)']];
        foreach ($design->fonts()->options() as $fid => $label) {
            $fonts[] = ['id' => $fid, 'label' => $label];
        }
        $config = [
            'kind' => $kind, 'id' => $id, 'base' => $this->base($kind, $id), 'tree' => $this->tree($kind, $row),
            'rev' => (int) $row['draft_rev'], 'has_draft' => $row['draft_json'] !== null,
            'title' => (string) ($kind === 'page' ? $row['title'] : $row['name']),
            'url' => $kind === 'page' ? PageService::url($row) : null, 'status' => $kind === 'page' ? $row['status'] : null,
            'is_home' => $kind === 'page' && (int) $row['is_home'] === 1, 'is_system' => $kind === 'page' && (int) $row['is_system'] === 1,
            'page' => $kind === 'page' ? ['title' => $row['title'], 'slug' => $row['slug'], 'seo_title' => (string) $row['seo_title'], 'seo_description' => (string) $row['seo_description']] : null,
            'can_publish' => $ctx->can('publish'), 'can_components' => $ctx->can('publish'), 'can_media' => $ctx->can('media'),
            'blocks' => BlockTypes::forEditor() + ['defaults' => array_map(fn($k) => BlockTypes::defaults($k), array_combine(array_keys(BlockTypes::all()), array_keys(BlockTypes::all())))],
            'style' => ['props' => BlockStyle::props(), 'groups' => BlockStyle::GROUPS, 'devices' => BlockStyle::DEVICES, 'tokens' => $tokens, 'fonts' => $fonts,
                'breakpoints' => ['tablet' => (int) $values['bp_tablet'], 'mobile' => (int) $values['bp_mobile']]],
            'pages' => array_map(fn($p) => ['id' => (int) $p['id'], 'title' => $p['title'], 'home' => (int) $p['is_home'] === 1],
                $this->app->db->all('SELECT id, title, is_home FROM pages ORDER BY is_home IS NULL, title')),
            'csrf' => $ctx->csrf,
        ];
        return Response::html($this->app->view->render('admin/builder/editor.twig', ['title' => $config['title'], 'config' => $config]));
    }

    /** Arbeitsbereich im Rahmen des Editors: echte Seite mit Entwurf und Auswahl-Markierungen. */
    public function canvas(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $row = $this->load($kind, $id);
        $page = $kind === 'page' ? $row : $this->componentPage($row);
        return Response::html($this->app->pageView()->html($page, ['query' => []], ['draft' => true, 'editing' => true, 'css_url' => null]));
    }

    /** Vorschau des gespeicherten Entwurfs in neuem Tab (wie Besucher die Seite nach dem Veröffentlichen sehen). */
    public function previewDraft(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $row = $this->load($kind, $id);
        $page = $kind === 'page' ? $row : $this->componentPage($row);
        $css = $this->base($kind, $id) . 'entwurf.css?v=' . (int) $row['draft_rev'];
        return Response::html($this->app->pageView()->html($page, ['query' => $req->query], ['draft' => true, 'css_url' => $css]))
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function draftCss(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $row = $this->load($kind, $id);
        return new Response($this->pages()->cssFor($this->tree($kind, $row), true), 200, ['Content-Type' => 'text/css; charset=utf-8']);
    }

    /** @param array<string,mixed> $c @return array<string,mixed> Hilfsseite, um einen globalen Baustein allein darzustellen */
    private function componentPage(array $c): array
    {
        return ['id' => 0, 'slug' => 'baustein', 'title' => (string) $c['name'], 'intro' => '', 'is_home' => null, 'seo_title' => null, 'seo_description' => null, 'css_hash' => '',
            'blocks_json' => $c['tree_json'], 'draft_json' => $c['draft_json'] ?? $c['tree_json']];
    }

    private function treeInput(Request $req): mixed
    {
        $raw = $req->post['tree'] ?? '';
        if (!is_string($raw) || strlen($raw) > 6_000_000) {
            return null;
        }
        try {
            return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** Live-Darstellung im Editor: Aufbau prüfen und mit den echten Templates ausgeben (nichts wird gespeichert). */
    public function render(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $row = $this->load($kind, $id);
        $tree = $this->treeInput($req);
        if ($tree === null) {
            return Response::json(['ok' => false, 'errors' => [['id' => '', 'msg' => 'Ungültige Daten.']]], 422);
        }
        [$clean, $errors] = $this->pages()->validator()->validate($tree);
        $page = $kind === 'page' ? $row : $this->componentPage($row);
        $r = $this->app->pageRenderer()->render($clean, ['page' => $page, 'editing' => true, 'draft' => true]);
        return Response::json(['ok' => true, 'html' => $r['html'], 'css' => $this->pages()->cssFor($clean, true), 'errors' => $errors]);
    }

    public function save(Request $req, AdminContext $ctx, string $kind, int $id, bool $publish): Response
    {
        $row = $this->load($kind, $id);
        $tree = $this->treeInput($req);
        if ($tree === null) {
            return Response::json(['ok' => false, 'errors' => [['id' => '', 'msg' => 'Ungültige Daten.']]], 422);
        }
        $table = $kind === 'page' ? 'pages' : 'builder_components';
        $force = $req->input('force') === '1';
        $rev = $force ? (int) $row['draft_rev'] : (int) $req->input('rev');
        $saved = $this->pages()->saveDraft($id, $tree, $rev, $ctx->id(), $table);
        if (!$saved['ok']) {
            return Response::json($saved, !empty($saved['conflict']) ? 409 : 422);
        }
        $label = $kind === 'page' ? 'Seite „' . $row['title'] . '“' : 'Globaler Baustein „' . $row['name'] . '“';
        $entity = $kind === 'page' ? 'page' : 'component';
        if (!$publish) {
            $this->app->audit->record($entity . '.draft', $label . ': Entwurf gespeichert', $entity, $id);
            return Response::json(['ok' => true, 'rev' => $saved['rev'], 'tree' => $saved['tree'], 'errors' => $saved['errors'], 'published' => false]);
        }
        $result = $kind === 'page' ? $this->pages()->publish($id, $ctx->id()) : $this->pages()->publishComponent($id, $ctx->id());
        if (!$result['ok']) {
            return Response::json(['ok' => false, 'saved' => true, 'rev' => $saved['rev'], 'errors' => $result['errors'],
                'message' => 'Der Entwurf ist gespeichert, aber noch nicht veröffentlicht. Bitte zuerst die markierten Punkte beheben.'], 422);
        }
        $this->app->audit->record($entity . '.published', $label . ' veröffentlicht', $entity, $id);
        $fresh = $this->load($kind, $id);
        return Response::json(['ok' => true, 'rev' => (int) $fresh['draft_rev'], 'tree' => $saved['tree'], 'errors' => [], 'published' => true]);
    }

    public function discard(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $this->load($kind, $id);
        $this->pages()->discardDraft($id, $kind === 'page' ? 'pages' : 'builder_components');
        $this->app->audit->record(($kind === 'page' ? 'page' : 'component') . '.draft_discarded', 'Entwurf verworfen', $kind === 'page' ? 'page' : 'component', $id);
        AdminKernel::flash('Entwurf verworfen. Der Editor zeigt wieder den veröffentlichten Stand.');
        return Response::redirect($this->base($kind, $id) . 'editor/', 303);
    }

    public function versions(Request $req, AdminContext $ctx, string $kind, int $id): Response
    {
        $this->load($kind, $id);
        $list = $this->pages()->revisions($id, $kind === 'page' ? PageService::REVISION_ENTITY : PageService::COMPONENT_ENTITY);
        return Response::json(array_map(fn($r) => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'at' => $r['created_at'], 'user' => $r['user_name']], $list));
    }

    public function version(Request $req, AdminContext $ctx, string $kind, int $id, int $rev): Response
    {
        $this->load($kind, $id);
        $tree = $this->pages()->revisionTree($id, $rev, $kind === 'page' ? PageService::REVISION_ENTITY : PageService::COMPONENT_ENTITY);
        if ($tree === null) {
            throw HttpException::notFound();
        }
        return Response::json(['tree' => $tree]);
    }

    /** Titel, Adresse und SEO-Angaben direkt aus dem Editor. */
    public function settings(Request $req, AdminContext $ctx, string $id): Response
    {
        $page = $this->load('page', (int) $id);
        $values = [
            'title' => mb_substr(trim(strip_tags($req->input('title'))), 0, 200),
            'seo_title' => mb_substr(trim(strip_tags($req->input('seo_title'))), 0, 70),
            'seo_description' => mb_substr(trim(strip_tags($req->input('seo_description'))), 0, 170),
        ];
        $errors = [];
        if ($values['title'] === '') {
            $errors['title'] = 'Bitte einen Titel angeben.';
        }
        if ((int) $page['is_system'] !== 1) {
            $slug = strtolower(trim($req->input('slug')));
            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 120) {
                $errors['slug'] = 'Nur Kleinbuchstaben, Ziffern und Bindestriche.';
            } else {
                $resource = new Resources\PageResource($this->app->db, fn() => '');
                $errors += $resource->validate(['slug' => $slug], (int) $id);
                $values['slug'] = $slug;
            }
        }
        if ($errors !== []) {
            return Response::json(['ok' => false, 'errors' => $errors], 422);
        }
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($values)));
        $this->app->db->run("UPDATE pages SET $sets WHERE id = ?", [...array_map(fn($v) => $v === '' ? null : $v, array_values($values)), (int) $id]);
        $this->app->audit->record('page.updated', 'Seiteneinstellungen geändert: ' . $values['title'], 'page', (int) $id,
            ['changes' => \Mea\Module\Audit\AuditLog::diff($page, $values)]);
        $fresh = $this->load('page', (int) $id);
        return Response::json(['ok' => true, 'url' => PageService::url($fresh)]);
    }

    // ---------- Globale Bausteine ----------

    public function components(Request $req, AdminContext $ctx): Response
    {
        $list = [];
        foreach ($this->pages()->components() as $c) {
            $list[] = $c + ['usage' => $this->pages()->componentUsage((int) $c['id'])];
        }
        return Response::html($this->app->view->render('admin/builder/components.twig', ['title' => 'Globale Bausteine', 'components' => $list]));
    }

    public function componentsJson(Request $req, AdminContext $ctx): Response
    {
        return Response::json(array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'tree' => ($this->pages()->component((int) $c['id'])['tree'] ?? [])],
            $this->pages()->components()));
    }

    public function createComponent(Request $req, AdminContext $ctx): Response
    {
        $r = $this->pages()->createComponent($req->input('name'), $this->treeInput($req), $ctx->id());
        if ($r['ok']) {
            $this->app->audit->record('component.created', 'Globaler Baustein angelegt: ' . mb_substr($req->input('name'), 0, 80), 'component', $r['id']);
        }
        return Response::json($r, $r['ok'] ? 200 : 422);
    }

    public function renameComponent(Request $req, AdminContext $ctx, string $id): Response
    {
        $name = mb_substr(trim(strip_tags($req->input('name'))), 0, 80);
        if ($name !== '') {
            $this->app->db->run('UPDATE builder_components SET name = ?, description = ?, is_locked = ?, updated_by = ? WHERE id = ?',
                [$name, mb_substr(trim(strip_tags($req->input('description'))), 0, 255) ?: null, $req->input('locked') === '1' ? 1 : 0, $ctx->id(), (int) $id]);
            $this->app->audit->record('component.updated', 'Globaler Baustein geändert: ' . $name, 'component', (int) $id);
            AdminKernel::flash('Gespeichert.');
        }
        return Response::redirect(self::COMPONENTS, 303);
    }

    public function deleteComponent(Request $req, AdminContext $ctx, string $id): Response
    {
        $c = $this->load('component', (int) $id);
        $usage = $this->pages()->componentUsage((int) $id);
        if ((int) $c['is_locked'] === 1) {
            AdminKernel::flash('Dieser Baustein ist geschützt. Bitte zuerst den Schutz aufheben.', 'error');
        } elseif ($usage !== []) {
            AdminKernel::flash('Der Baustein wird noch verwendet: ' . implode(', ', array_column($usage, 'title')) . '. Bitte dort zuerst entfernen.', 'error');
        } else {
            $this->app->db->run('DELETE FROM builder_components WHERE id = ?', [(int) $id]);
            $this->app->audit->record('component.deleted', 'Globaler Baustein gelöscht: ' . $c['name'], 'component', (int) $id);
            AdminKernel::flash('Baustein gelöscht.');
        }
        return Response::redirect(self::COMPONENTS, 303);
    }
}
