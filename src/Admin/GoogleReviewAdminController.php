<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;

/** Google-Bewertungen: Verbindung zum Unternehmensprofil, Abruf auf Knopfdruck, Anzeigen/Ausblenden. */
final class GoogleReviewAdminController
{
    private const BASE = '/verwaltung/google-bewertungen/';

    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->post(self::BASE . 'verbindung/', 'content', $this->saveConnection(...));
        $k->post(self::BASE . 'abrufen/', 'content', $this->syncNow(...));
        $k->post(self::BASE . '{id:\d+}/aktiv/', 'content', $this->toggle(...));
    }

    public function saveConnection(Request $req, AdminContext $ctx): Response
    {
        $url = trim((string) $req->input('profile_url'));
        $rating = trim(str_replace(',', '.', (string) $req->input('rating')));
        $count = trim((string) $req->input('count'));
        $asOf = mb_substr(trim((string) $req->input('as_of')), 0, 20);
        $errors = [];
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://') || strlen($url) > 1000)) {
            $errors[] = 'Der Profil-Link muss mit https:// beginnen.';
        }
        if ($rating !== '' && (!is_numeric($rating) || (float) $rating < 1 || (float) $rating > 5)) {
            $errors[] = 'Sterne: bitte eine Zahl von 1,0 bis 5,0 eintragen oder leer lassen.';
        }
        if ($rating !== '' && (!ctype_digit($count) || (int) $count < 1)) {
            $errors[] = 'Bitte auch die Anzahl der Bewertungen eintragen.';
        }
        $placeId = trim((string) $req->input('place_id'));
        if ($placeId !== '' && !preg_match('/^[A-Za-z0-9_-]{10,300}$/', $placeId)) {
            $errors[] = 'Die Place-ID enthält ungültige Zeichen.';
        }
        if ($errors !== []) {
            AdminKernel::flash(implode(' ', $errors), 'error');
            return Response::redirect(self::BASE, 303);
        }
        $g = (array) ($this->app->settings->get('google', []) ?? []);
        $before = $g;
        $g['profile_url'] = $url;
        $g['rating'] = $rating === '' ? '' : number_format(round((float) $rating, 1), 1, ',', '');
        $g['count'] = $rating === '' ? 0 : (int) $count;
        $g['as_of'] = $asOf;
        $this->app->settings->set('google', $g);
        $key = (string) $req->input('api_key');
        $this->app->googleReviews()->saveConnection($req->input('remove_key') === '1' ? '-' : $key, $placeId);
        $this->app->audit->record('settings.updated', 'Google-Bewertungen: Verbindung geändert', 'settings', null, [
            'changes' => ['google' => [$before, $g]], 'api_key' => $key !== '' ? 'neu' : ($req->input('remove_key') === '1' ? 'gelöscht' : 'unverändert'),
        ]);
        AdminKernel::flash('Einstellungen für Google-Bewertungen gespeichert.');
        return Response::redirect(self::BASE, 303);
    }

    public function syncNow(Request $req, AdminContext $ctx): Response
    {
        $g = $this->app->googleReviews();
        if (!$g->isConfigured()) {
            AdminKernel::flash('Bitte zuerst einen API-Schlüssel eintragen.', 'error');
            return Response::redirect(self::BASE, 303);
        }
        try {
            $r = $g->sync($this->app->clock->now());
            $this->app->audit->record('google_reviews.synced', 'Google-Bewertungen abgerufen', 'settings', null, $r);
            AdminKernel::flash('Von Google abgerufen: ' . ($r['rating'] === null ? 'kein Gesamtwert' : number_format($r['rating'], 1, ',', '') . ' Sterne aus ' . $r['count'] . ' Bewertungen')
                . ', ' . $r['reviews'] . ' Bewertungstexte.');
        } catch (\RuntimeException $e) {
            AdminKernel::flash('Abruf fehlgeschlagen: ' . $e->getMessage(), 'error');
        }
        return Response::redirect(self::BASE, 303);
    }

    public function toggle(Request $req, AdminContext $ctx, string $id): Response
    {
        $r = $this->app->db->one('SELECT id, author_name, is_active FROM google_reviews WHERE id = ?', [(int) $id]);
        if ($r === null) {
            throw HttpException::notFound();
        }
        $to = $req->input('value') === '1' ? 1 : 0;
        if ($to !== (int) $r['is_active']) {
            $this->app->db->run('UPDATE google_reviews SET is_active = ? WHERE id = ?', [$to, (int) $id]);
            $this->app->audit->record('google_review.active', 'Google-Bewertung ' . ($to ? 'angezeigt' : 'ausgeblendet') . ': ' . $r['author_name'], 'google_review', (int) $id, ['nachher' => $to]);
        }
        AdminKernel::flash('Die Bewertung von „' . $r['author_name'] . '“ ist ' . ($to ? 'jetzt auf der Website sichtbar.' : 'jetzt ausgeblendet.'));
        return Response::redirect(self::BASE, 303);
    }
}
