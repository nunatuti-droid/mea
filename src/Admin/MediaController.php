<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Media\ImageVariantGenerator;

/** Mediathek: Hochladen (Bilder, PDF), Alternativtext, Verwendungen, Löschen. */
final class MediaController
{
    private const MAX_IMAGE = 15 * 1024 * 1024;
    private const MAX_PDF = 20 * 1024 * 1024;

    public function __construct(private App $app) {}

    private function generator(): ImageVariantGenerator
    {
        return new ImageVariantGenerator($this->app->db, $this->app->root . '/storage/uploads', $this->app->root . '/public/media');
    }

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/medien/', 'media', $this->index(...));
        $k->post('/verwaltung/medien/hochladen/', 'media', $this->upload(...));
        $k->get('/verwaltung/medien/{id:\d+}/', 'media', $this->edit(...));
        $k->post('/verwaltung/medien/{id:\d+}/', 'media', $this->update(...));
        $k->post('/verwaltung/medien/{id:\d+}/loeschen/', 'media', $this->delete(...));
        $k->get('/verwaltung/medien/auswahl.json', 'dashboard', $this->pickerJson(...));
        $k->get('/verwaltung/links.json', 'dashboard', $this->linksJson(...));
    }

    public function index(Request $req, AdminContext $ctx): Response
    {
        $q = mb_substr($req->input('q'), 0, 100);
        $kind = in_array($req->input('art'), ['image', 'document'], true) ? $req->input('art') : '';
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(m.alt_text LIKE ? OR m.original_name LIKE ? OR m.title LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        if ($kind !== '') {
            $where[] = 'm.kind = ?';
            $params[] = $kind;
        }
        $items = $this->app->db->all('SELECT m.*, (SELECT path FROM media_variants v WHERE v.media_id = m.id ORDER BY v.width LIMIT 1) AS thumb FROM media m'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY m.id DESC LIMIT 300', $params);
        return Response::html($this->app->view->render('admin/media/index.twig', ['title' => 'Mediathek', 'items' => $items, 'q' => $q, 'kind' => $kind,
            'max_upload' => ini_get('upload_max_filesize')]));
    }

    public function upload(Request $req, AdminContext $ctx): Response
    {
        $files = $_FILES['files'] ?? null;
        $alt = trim(mb_substr($req->input('alt_text'), 0, 300));
        if (!is_array($files) || !is_array($files['name'] ?? null)) {
            AdminKernel::flash('Bitte mindestens eine Datei wählen.', 'error');
            return Response::redirect('/verwaltung/medien/', 303);
        }
        $ok = 0;
        $errors = [];
        foreach ($files['name'] as $i => $name) {
            $tmp = (string) $files['tmp_name'][$i];
            $name = basename((string) $name);
            if ((int) $files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $errors[] = "$name: Upload fehlgeschlagen (zu groß oder unterbrochen).";
                continue;
            }
            $size = (int) $files['size'][$i];
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
            try {
                if ($mime === 'application/pdf') {
                    if ($size > self::MAX_PDF) {
                        throw new \InvalidArgumentException('PDF größer als 20 MB.');
                    }
                    $id = $this->generator()->importDocument($tmp, $name, $alt !== '' ? $alt : pathinfo($name, PATHINFO_FILENAME));
                } else {
                    if ($size > self::MAX_IMAGE) {
                        throw new \InvalidArgumentException('Bild größer als 15 MB.');
                    }
                    if ($alt === '') {
                        throw new \InvalidArgumentException('Bitte einen Alternativtext angeben (beschreibt das Bild für blinde Menschen und Suchmaschinen).');
                    }
                    $id = $this->generator()->import($tmp, $alt, '', '', $name);
                }
                $this->app->audit->record('media.uploaded', 'Datei hochgeladen: ' . $name, 'media', $id);
                $ok++;
            } catch (\InvalidArgumentException $e) {
                $errors[] = "$name: " . $e->getMessage();
            } catch (\Throwable $e) {
                $this->app->log->error('Upload', ['error' => $e->getMessage()]);
                $errors[] = "$name: konnte nicht verarbeitet werden.";
            }
        }
        AdminKernel::flash(trim(($ok ? "$ok Datei(en) hochgeladen. " : '') . implode(' ', $errors)), $errors ? 'error' : 'success');
        return Response::redirect('/verwaltung/medien/', 303);
    }

    /** @return array<string,mixed> */
    private function item(string $id): array
    {
        $m = $this->app->db->one('SELECT * FROM media WHERE id = ?', [(int) $id]);
        if ($m === null) {
            throw HttpException::notFound();
        }
        $m['variants'] = $this->app->db->all('SELECT * FROM media_variants WHERE media_id = ? ORDER BY width', [(int) $id]);
        return $m;
    }

    public function edit(Request $req, AdminContext $ctx, string $id): Response
    {
        $m = $this->item($id);
        return Response::html($this->app->view->render('admin/media/edit.twig', ['title' => $m['original_name'], 'm' => $m, 'usages' => $this->usages((int) $id), 'errors' => []]));
    }

    public function update(Request $req, AdminContext $ctx, string $id): Response
    {
        $m = $this->item($id);
        $alt = trim(mb_substr($req->input('alt_text'), 0, 300));
        if ($m['kind'] === 'image' && $alt === '' && $req->input('decorative') !== '1') {
            return Response::html($this->app->view->render('admin/media/edit.twig', ['title' => $m['original_name'], 'm' => $m, 'usages' => $this->usages((int) $id),
                'errors' => ['alt_text' => 'Bitte einen Alternativtext angeben oder das Bild als rein dekorativ kennzeichnen.']]), 422);
        }
        $this->app->db->run('UPDATE media SET alt_text = ?, title = ?, credit = ? WHERE id = ?', [$alt, mb_substr($req->input('title'), 0, 200), mb_substr($req->input('credit'), 0, 200), (int) $id]);
        $this->app->audit->record('media.updated', 'Medium geändert: ' . $m['original_name'], 'media', (int) $id, ['alt_text' => [$m['alt_text'], $alt]]);
        AdminKernel::flash('Gespeichert.');
        return Response::redirect('/verwaltung/medien/' . (int) $id . '/', 303);
    }

    public function delete(Request $req, AdminContext $ctx, string $id): Response
    {
        $m = $this->item($id);
        $usages = $this->usages((int) $id);
        if ($usages !== []) {
            AdminKernel::flash('Diese Datei wird noch verwendet und kann nicht gelöscht werden.', 'error');
            return Response::redirect('/verwaltung/medien/' . (int) $id . '/', 303);
        }
        $this->generator()->deleteFiles((int) $id);
        $this->app->db->run('DELETE FROM media WHERE id = ?', [(int) $id]);
        $this->app->audit->record('media.deleted', 'Medium gelöscht: ' . $m['original_name'], 'media', (int) $id);
        AdminKernel::flash('Datei gelöscht.');
        return Response::redirect('/verwaltung/medien/', 303);
    }

    /** Wo wird ein Medium verwendet? Prüft alle Bildfelder, Zuordnungen, Einstellungen und Texte. @return list<array{label:string, url:string}> */
    public function usages(int $id): array
    {
        $db = $this->app->db;
        $out = [];
        $checks = [
            ['exam_types', ['card_media_id', 'hero_media_id'], 'public_name', '/verwaltung/pruefungen/', 'Prüfung'],
            ['services', ['card_media_id', 'hero_media_id'], 'title', '/verwaltung/leistungen/', 'Leistung'],
            ['pages', ['hero_media_id'], 'title', '/verwaltung/seiten/', 'Seite'],
            ['locations', ['image_media_id'], 'name', '/verwaltung/standorte/', 'Standort'],
            ['exam_providers', ['logo_media_id'], 'name', '/verwaltung/anbieter/', 'Anbieter'],
        ];
        foreach ($checks as [$table, $cols, $label, $url, $type]) {
            $where = implode(' OR ', array_map(fn($c) => "$c = ?", $cols));
            foreach ($db->all("SELECT id, $label AS l FROM $table WHERE $where", array_fill(0, count($cols), $id)) as $r) {
                $out[] = ['label' => "$type: {$r['l']}", 'url' => $url . $r['id'] . '/'];
            }
        }
        foreach ($db->all('SELECT x.exam_type_id, e.public_name FROM exam_type_media x JOIN exam_types e ON e.id = x.exam_type_id WHERE x.media_id = ?', [$id]) as $r) {
            $out[] = ['label' => 'Prüfung (Dateien): ' . $r['public_name'], 'url' => '/verwaltung/pruefungen/' . $r['exam_type_id'] . '/#dokumente'];
        }
        $pattern = '%/media/' . $id . '-%';
        $docPattern = '%/media/dokumente/' . $id . '-%';
        foreach ([['pages', 'title', '/verwaltung/seiten/', 'Seite (Text)'], ['services', 'title', '/verwaltung/leistungen/', 'Leistung (Text)'], ['exam_types', 'public_name', '/verwaltung/pruefungen/', 'Prüfung (Text)'], ['faqs', 'question', '/verwaltung/faq/', 'FAQ']] as [$table, $label, $url, $type]) {
            $cols = $table === 'faqs' ? ['answer_html'] : ($table === 'exam_types' ? ['body_html', 'target_group_html', 'requirements_html', 'procedure_html'] : ['body_html']);
            $cond = implode(' OR ', array_map(fn($c) => "$c LIKE ? OR $c LIKE ?", $cols));
            $params = [];
            foreach ($cols as $_) {
                array_push($params, $pattern, $docPattern);
            }
            foreach ($db->all("SELECT id, $label AS l FROM $table WHERE $cond", $params) as $r) {
                $out[] = ['label' => "$type: {$r['l']}", 'url' => $url . $r['id'] . '/'];
            }
        }
        foreach ($db->all("SELECT id, title FROM services WHERE download_url LIKE ?", [$docPattern]) as $r) {
            $out[] = ['label' => 'Leistung (Download): ' . $r['title'], 'url' => '/verwaltung/leistungen/' . $r['id'] . '/'];
        }
        foreach ($db->all('SELECT setting_key, value_json FROM settings') as $s) {
            if (self::jsonReferences((array) json_decode((string) $s['value_json'], true), $id)) {
                $out[] = ['label' => 'Einstellung: ' . $s['setting_key'], 'url' => '/verwaltung/einstellungen/'];
            }
        }
        return $out;
    }

    /** @param array<mixed> $data */
    private static function jsonReferences(array $data, int $id): bool
    {
        foreach ($data as $k => $v) {
            if (is_array($v) && self::jsonReferences($v, $id)) {
                return true;
            }
            if (is_string($k) && str_ends_with($k, 'media_id') && (int) $v === $id) {
                return true;
            }
            if (is_string($k) && str_ends_with($k, '_logo') && (int) $v === $id) {
                return true;
            }
            if ($k === 'logos' && is_array($v) && in_array($id, array_map('intval', $v), true)) {
                return true;
            }
        }
        return false;
    }

    public function pickerJson(Request $req, AdminContext $ctx): Response
    {
        $rows = $this->app->db->all("SELECT m.id, m.alt_text, m.width, m.height FROM media m WHERE m.kind = 'image' ORDER BY m.id DESC LIMIT 300");
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'alt' => (string) $r['alt_text'], 'thumb' => $this->app->media->url((int) $r['id'], 480),
                'src' => $this->app->media->url((int) $r['id'], 960), 'width' => min(960, (int) $r['width']),
                'height' => (int) round(min(960, (int) $r['width']) * (int) $r['height'] / max(1, (int) $r['width']))];
        }
        return Response::json($out);
    }

    public function linksJson(Request $req, AdminContext $ctx): Response
    {
        $db = $this->app->db;
        $links = [];
        foreach ($db->all("SELECT slug, title FROM pages WHERE status = 'published'") as $p) {
            $links[] = ['url' => '/' . $p['slug'] . '/', 'label' => $p['title']];
        }
        foreach ($db->all("SELECT s.slug, s.title, p.slug AS parent FROM services s LEFT JOIN services p ON p.id = s.parent_id WHERE s.status = 'published'") as $s) {
            $links[] = ['url' => '/' . ($s['parent'] ? $s['parent'] . '/' : '') . $s['slug'] . '/', 'label' => $s['title']];
        }
        foreach ($db->all("SELECT slug, public_name FROM exam_types WHERE status = 'published'") as $e) {
            $links[] = ['url' => '/pruefungen/' . $e['slug'] . '/', 'label' => $e['public_name']];
        }
        foreach ($db->all("SELECT public_path, title FROM media WHERE kind = 'document'") as $d) {
            $links[] = ['url' => '/media/' . $d['public_path'], 'label' => 'PDF: ' . $d['title']];
        }
        return Response::json($links);
    }
}
