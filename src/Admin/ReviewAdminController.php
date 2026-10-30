<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;

/** Schnellschalter für Stimmen von Teilnehmenden: auf der Website zeigen oder ausblenden. */
final class ReviewAdminController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->post('/verwaltung/bewertungen/{id:\d+}/aktiv/', 'content', $this->toggle(...));
    }

    public function toggle(Request $req, AdminContext $ctx, string $id): Response
    {
        $r = $this->app->db->one('SELECT id, display_name, is_active FROM reviews WHERE id = ?', [(int) $id]);
        if ($r === null) {
            throw HttpException::notFound();
        }
        $to = $req->input('value') === '1' ? 1 : 0;
        if ($to !== (int) $r['is_active']) {
            $this->app->db->run('UPDATE reviews SET is_active = ? WHERE id = ?', [$to, (int) $id]);
            $this->app->audit->record('review.active', 'Bewertung ' . ($to ? 'angezeigt' : 'ausgeblendet') . ': ' . $r['display_name'], 'review', (int) $id, ['nachher' => $to]);
        }
        AdminKernel::flash('Die Bewertung von „' . $r['display_name'] . '“ ist ' . ($to ? 'jetzt auf der Website sichtbar.' : 'jetzt ausgeblendet.'));
        return Response::redirect('/verwaltung/bewertungen/', 303);
    }
}
