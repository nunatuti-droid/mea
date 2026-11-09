<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;
use Mea\Core\HttpException;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Module\Auth\Roles;

/**
 * Benutzerverwaltung: einladen, Rolle ändern, sperren, Zwei-Faktor zurücksetzen, Sitzungen beenden.
 * Sicherheitskritische Änderungen verlangen eine erneute Bestätigung mit Passwort und Code.
 * Der letzte aktive Administrator kann weder gesperrt noch herabgestuft werden.
 */
final class UserController
{
    public function __construct(private App $app) {}

    public function register(AdminKernel $k): void
    {
        $k->get('/verwaltung/benutzer/', 'users', $this->index(...));
        $k->post('/verwaltung/benutzer/einladen/', 'users', $this->invite(...));
        $k->get('/verwaltung/benutzer/{id:\d+}/', 'users', $this->show(...));
        $k->post('/verwaltung/benutzer/{id:\d+}/', 'users', $this->update(...));
        $k->post('/verwaltung/benutzer/{id:\d+}/einladung/', 'users', $this->reinvite(...));
        $k->post('/verwaltung/benutzer/{id:\d+}/einladung-widerrufen/', 'users', $this->revokeInvite(...));
        $k->post('/verwaltung/benutzer/{id:\d+}/2fa-zuruecksetzen/', 'users', $this->resetTotp(...));
        $k->post('/verwaltung/benutzer/{id:\d+}/sitzungen-beenden/', 'users', $this->revokeSessions(...));
    }

    private function needsReauth(AdminContext $ctx, string $back): ?Response
    {
        if ($this->app->auth->hasRecentReauth($ctx->session)) {
            return null;
        }
        return Response::redirect('/verwaltung/bestaetigen/?weiter=' . rawurlencode($back), 303);
    }

    /** @return array<string,mixed> */
    private function user(string $id): array
    {
        $u = $this->app->auth->user((int) $id);
        if ($u === null) {
            throw HttpException::notFound();
        }
        return $u;
    }

    public function index(Request $req, AdminContext $ctx, array $errors = [], array $values = [], int $status = 200): Response
    {
        $users = $this->app->db->all("SELECT u.*, (SELECT MAX(t.expires_at) FROM user_tokens t WHERE t.user_id = u.id AND t.type = 'invite' AND t.used_at IS NULL AND t.revoked_at IS NULL) AS invite_expires
            FROM users u ORDER BY u.status = 'disabled', u.name");
        return Response::html($this->app->view->render('admin/users/index.twig', [
            'title' => 'Benutzer', 'users' => $users, 'roles' => Roles::LABELS, 'errors' => $errors, 'values' => $values,
            'permissions' => Roles::PERMISSIONS, 'matrix' => array_map(fn($r) => array_map(fn($p) => Roles::can($r, $p), array_combine(array_keys(Roles::PERMISSIONS), array_keys(Roles::PERMISSIONS))), array_combine(array_keys(Roles::LABELS), array_keys(Roles::LABELS))),
        ]), $status);
    }

    public function invite(Request $req, AdminContext $ctx): Response
    {
        $email = mb_strtolower(trim($req->input('email')));
        $name = trim(mb_substr($req->input('name'), 0, 120));
        $role = $req->input('role');
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if ($name === '') {
            $errors['name'] = 'Bitte einen Namen angeben.';
        }
        if (!Roles::valid($role)) {
            $errors['role'] = 'Bitte eine Rolle wählen.';
        }
        if ($errors === [] && $role === 'admin' && ($r = $this->needsReauth($ctx, '/verwaltung/benutzer/')) !== null) {
            return $r;
        }
        if ($errors !== []) {
            return $this->index($req, $ctx, $errors, ['email' => $email, 'name' => $name, 'role' => $role], 422);
        }
        try {
            $this->app->auth->invite($email, $name, $role, $ctx->id());
        } catch (\DomainException $e) {
            return $this->index($req, $ctx, ['email' => $e->getMessage()], ['email' => $email, 'name' => $name, 'role' => $role], 422);
        }
        AdminKernel::flash("Einladung an $email ist unterwegs. Der Link ist 48 Stunden gültig.");
        return Response::redirect('/verwaltung/benutzer/', 303);
    }

    public function show(Request $req, AdminContext $ctx, string $id): Response
    {
        $u = $this->user($id);
        return Response::html($this->app->view->render('admin/users/show.twig', [
            'title' => $u['name'], 'u' => $u, 'roles' => Roles::LABELS,
            'sessions' => $this->app->auth->sessionsOf((int) $id),
            'is_last_admin' => $this->app->auth->isLastActiveAdmin((int) $id),
            'is_self' => (int) $id === $ctx->id(),
            'activity' => $this->app->db->all('SELECT created_at, summary FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 15', [(int) $id]),
        ]));
    }

    public function update(Request $req, AdminContext $ctx, string $id): Response
    {
        $u = $this->user($id);
        $back = '/verwaltung/benutzer/' . (int) $id . '/';
        $role = $req->input('role');
        $status = $req->input('status');
        $name = trim(mb_substr($req->input('name'), 0, 120));
        if (!Roles::valid($role) || !in_array($status, ['active', 'disabled', 'invited'], true) || $name === '') {
            AdminKernel::flash('Ungültige Angaben.', 'error');
            return Response::redirect($back, 303);
        }
        if ($status === 'active' && $u['status'] === 'invited') {
            $status = 'invited'; // aktiv wird ein Konto erst nach Einrichtung der Zwei-Faktor-Anmeldung
        }
        $critical = $role !== $u['role'] || $status !== $u['status'];
        if ($critical && ($r = $this->needsReauth($ctx, $back)) !== null) {
            return $r;
        }
        if ($critical && $this->app->auth->isLastActiveAdmin((int) $id) && ($role !== 'admin' || $status !== 'active')) {
            AdminKernel::flash('Das ist der letzte aktive Administrator. Ernennen Sie zuerst eine weitere Person zum Administrator.', 'error');
            return Response::redirect($back, 303);
        }
        $this->app->db->run('UPDATE users SET name = ?, role = ?, status = ? WHERE id = ?', [$name, $role, $status, (int) $id]);
        if ($status === 'disabled' || $role !== $u['role']) {
            $this->app->auth->revokeSessions((int) $id, (int) $id === $ctx->id() ? (int) $ctx->session['id'] : null);
        }
        $this->app->audit->record('user.updated', 'Benutzer geändert: ' . $name, 'user', (int) $id, ['changes' => \Mea\Module\Audit\AuditLog::diff($u, ['name' => $name, 'role' => $role, 'status' => $status])]);
        AdminKernel::flash('Gespeichert.' . ($status === 'disabled' ? ' Das Konto ist gesperrt und alle Sitzungen wurden beendet.' : ''));
        return Response::redirect($back, 303);
    }

    public function reinvite(Request $req, AdminContext $ctx, string $id): Response
    {
        $u = $this->user($id);
        if ($u['status'] !== 'invited') {
            AdminKernel::flash('Dieses Konto ist bereits eingerichtet.', 'error');
        } else {
            $this->app->auth->invite((string) $u['email'], (string) $u['name'], (string) $u['role'], $ctx->id());
            AdminKernel::flash('Neue Einladung gesendet. Der alte Link ist ungültig.');
        }
        return Response::redirect('/verwaltung/benutzer/' . (int) $id . '/', 303);
    }

    public function revokeInvite(Request $req, AdminContext $ctx, string $id): Response
    {
        $u = $this->user($id);
        $this->app->auth->revokeInvites((int) $id);
        if ($u['status'] === 'invited' && $u['password_hash'] === null) {
            $this->app->db->run("UPDATE users SET status = 'disabled' WHERE id = ?", [(int) $id]);
        }
        $this->app->audit->record('user.invite_revoked', 'Einladung widerrufen: ' . $u['email'], 'user', (int) $id);
        AdminKernel::flash('Einladung widerrufen.');
        return Response::redirect('/verwaltung/benutzer/' . (int) $id . '/', 303);
    }

    public function resetTotp(Request $req, AdminContext $ctx, string $id): Response
    {
        $u = $this->user($id);
        $back = '/verwaltung/benutzer/' . (int) $id . '/';
        if (($r = $this->needsReauth($ctx, $back)) !== null) {
            return $r;
        }
        $this->app->auth->resetTotp((int) $id);
        $this->app->audit->record('user.2fa_reset', 'Zwei-Faktor-Anmeldung zurückgesetzt: ' . $u['email'], 'user', (int) $id);
        $this->app->mailer->queue('security_notice', (string) $u['email'], (string) $u['name'], ['name' => $u['name'],
            'hinweis' => 'Ein Administrator hat Ihre Zwei-Faktor-Anmeldung zurückgesetzt. Bei der nächsten Anmeldung richten Sie sie neu ein. Wenn Sie das nicht veranlasst haben, melden Sie sich bitte sofort.'],
            'sec-2fareset-' . $id . '-' . time(), 'user', (int) $id);
        AdminKernel::flash('Zwei-Faktor-Anmeldung zurückgesetzt. Die Person richtet sie bei der nächsten Anmeldung neu ein.');
        return Response::redirect($back, 303);
    }

    public function revokeSessions(Request $req, AdminContext $ctx, string $id): Response
    {
        $u = $this->user($id);
        $n = $this->app->auth->revokeSessions((int) $id, (int) $id === $ctx->id() ? (int) $ctx->session['id'] : null);
        $this->app->audit->record('user.sessions_revoked', "Sitzungen beendet ($n): " . $u['email'], 'user', (int) $id);
        AdminKernel::flash("$n Sitzungen beendet.");
        return Response::redirect('/verwaltung/benutzer/' . (int) $id . '/', 303);
    }
}
