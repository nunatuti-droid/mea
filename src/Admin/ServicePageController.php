<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Builder\ServicePageConverter;

/** Leistung → Seite im Seitenbaukasten: legt eine Entwurfsseite mit denselben Inhalten an, damit sie visuell bearbeitet werden kann. */
final class ServicePageController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->post('/verwaltung/leistungen/{id:\d+}/als-seite/', 'content', $this->convert(...));
    }

    public function convert(Request $req, AdminContext $ctx, string $id): Response
    {
        $row = $this->app->db->one('SELECT * FROM services WHERE id = ?', [(int) $id]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        $company = (array) $this->app->settings->get('company', []);
        $r = (new ServicePageConverter($this->app->content))->convert($this->app->content->decodeService($row), [
            'phone' => (string) ($company['phone'] ?? ''), 'phone_display' => (string) ($company['phone_display'] ?? '')]);
        $back = '/verwaltung/leistungen/' . (int) $id . '/';
        if ($r['tree'] === null) {
            AdminKernel::flash('Diese Leistung lässt sich noch nicht umwandeln: ' . $r['reason']);
            return Response::redirect($back, 303);
        }
        $existing = $this->app->db->one('SELECT id FROM pages WHERE slug = ?', [$r['slug']]);
        if ($existing !== null) {
            AdminKernel::flash('Für diese Adresse gibt es schon eine Seite. Sie wird hier geöffnet.');
            return Response::redirect('/verwaltung/seiten/' . $existing['id'] . '/editor/', 303);
        }
        $pageId = $this->app->db->insert('pages', [
            'slug' => $r['slug'], 'title' => (string) $row['title'], 'intro' => '', 'template' => 'default', 'status' => 'draft', 'is_system' => 0,
            'blocks_json' => '[]', 'draft_json' => json_encode($r['tree'], JSON_UNESCAPED_UNICODE),
        ]);
        $this->app->audit->record('page.from_service', 'Leistung als Seite angelegt (Entwurf): ' . $row['title'], 'page', $pageId, ['leistung' => (int) $id]);
        AdminKernel::flash('Die Seite wurde als Entwurf angelegt. Veröffentlichen Sie sie, wenn sie so aussieht wie gewünscht; dann ersetzt sie die bisherige Leistungsseite unter derselben Adresse.');
        return Response::redirect('/verwaltung/seiten/' . $pageId . '/editor/', 303);
    }
}
