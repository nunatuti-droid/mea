<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\Crypto;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Core\Session;
use Mea\Core\View;
use Mea\Module\Auth\AuthService;
use Mea\Module\Auth\PasswordPolicy;
use Mea\Module\Auth\Roles;

/** Anmeldung, zweiter Faktor, Einrichtung, Einladung, Passwort vergessen, Abmeldung, eigenes Konto. */
final class AuthController
{
    private const PENDING_TTL = 300;
    private const SETUP_TTL = 900;

    public function __construct(private AuthService $auth, private View $view, private Crypto $crypto, private ?\Mea\Module\Security\Firewall $firewall = null) {}

    public function register(AdminKernel $k): void
    {
        $k->publicGet('/verwaltung/anmelden/', $this->loginForm(...));
        $k->publicPost('/verwaltung/anmelden/', $this->login(...));
        $k->publicGet('/verwaltung/anmelden/code/', $this->codeForm(...));
        $k->publicPost('/verwaltung/anmelden/code/', $this->code(...));
        $k->publicGet('/verwaltung/einrichten/', $this->setupForm(...));
        $k->publicPost('/verwaltung/einrichten/', $this->setup(...));
        $k->publicGet('/verwaltung/backup-codes/', $this->showCodes(...));
        $k->publicGet('/verwaltung/einladung/{token:[A-Za-z0-9_-]{43}}/', $this->inviteForm(...));
        $k->publicPost('/verwaltung/einladung/{token:[A-Za-z0-9_-]{43}}/', $this->acceptInvite(...));
        $k->publicGet('/verwaltung/passwort-vergessen/', $this->forgotForm(...));
        $k->publicPost('/verwaltung/passwort-vergessen/', $this->forgot(...));
        $k->publicGet('/verwaltung/passwort/neu/{token:[A-Za-z0-9_-]{43}}/', $this->resetForm(...));
        $k->publicPost('/verwaltung/passwort/neu/{token:[A-Za-z0-9_-]{43}}/', $this->reset(...));
        $k->post('/verwaltung/abmelden/', 'dashboard', $this->logout(...));
        $k->get('/verwaltung/konto/', 'dashboard', $this->account(...));
        $k->post('/verwaltung/konto/passwort/', 'dashboard', $this->changePassword(...));
        $k->post('/verwaltung/konto/backup-codes/', 'dashboard', $this->newCodes(...));
        $k->post('/verwaltung/konto/sitzungen/abmelden/', 'dashboard', $this->revokeOthers(...));
        $k->get('/verwaltung/bestaetigen/', 'dashboard', $this->reauthForm(...));
        $k->post('/verwaltung/bestaetigen/', 'dashboard', $this->reauth(...));
    }

    private function render(string $tpl, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render('admin/auth/' . $tpl . '.twig', $data), $status);
    }

    private static function safeNext(string $next): string
    {
        return preg_match('#^/verwaltung/[A-Za-z0-9/_\-.?=&%]*$#', $next) && !str_contains($next, '//') ? $next : '/verwaltung/';
    }

    private static function deviceCookieName(Request $req): string
    {
        return $req->isSecure() ? '__Host-mea-device' : 'mea-device';
    }

    // ---- Anmeldung ----

    public function loginForm(Request $req): Response
    {
        if ($this->auth->resolveSession((string) Session::get('admin_token', '')) !== null) {
            return Response::redirect('/verwaltung/', 303);
        }
        return $this->render('login', ['next' => self::safeNext($req->input('weiter')), 'email' => '', 'notice' => Session::flash('auth_notice')]);
    }

    public function login(Request $req): Response
    {
        $email = mb_substr($req->input('email'), 0, 190);
        $next = self::safeNext($req->input('weiter'));
        // Unsichtbares Feld: nur Bots füllen es aus
        if ($req->input('website') !== '') {
            $this->firewall?->record('bot_login', $req, 5, 'warning');
            return $this->render('login', ['next' => $next, 'email' => $email, 'error' => 'E-Mail-Adresse oder Passwort ist falsch.'], 422);
        }
        $res = $this->auth->attemptPassword($email, (string) ($req->post['password'] ?? ''), $req->ip());
        if ($res['status'] !== 'ok') {
            $this->firewall?->record('login_failed', $req, 1, 'warning'); // 10 Fehlversuche in 10 Minuten = Sperre der Verbindung (Konto sperrt schon nach 5)
            $msg = $res['status'] === 'blocked'
                ? 'Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten und versuchen Sie es erneut.'
                : 'E-Mail-Adresse oder Passwort ist falsch.';
            return $this->render('login', ['next' => $next, 'email' => $email, 'error' => $msg], $res['status'] === 'blocked' ? 429 : 422);
        }
        $user = $res['user'];
        Session::regenerate();
        if ($user['totp_enabled_at'] === null) {
            Session::set('auth_setup', ['uid' => (int) $user['id'], 'at' => time(), 'next' => $next]);
            return Response::redirect('/verwaltung/einrichten/', 303);
        }
        Session::set('auth_pending', ['uid' => (int) $user['id'], 'at' => time(), 'next' => $next]);
        return Response::redirect('/verwaltung/anmelden/code/', 303);
    }

    /** @return array<string,mixed>|null */
    private function pending(string $key, int $ttl): ?array
    {
        $p = Session::get($key);
        if (!is_array($p) || time() - (int) ($p['at'] ?? 0) > $ttl) {
            Session::forget($key);
            return null;
        }
        $user = $this->auth->user((int) $p['uid']);
        return $user === null ? null : ['user' => $user] + $p;
    }

    public function codeForm(Request $req): Response
    {
        if ($this->pending('auth_pending', self::PENDING_TTL) === null) {
            return Response::redirect('/verwaltung/anmelden/', 303);
        }
        return $this->render('code');
    }

    public function code(Request $req): Response
    {
        $p = $this->pending('auth_pending', self::PENDING_TTL);
        if ($p === null) {
            Session::flash('auth_notice', 'Die Anmeldung ist abgelaufen. Bitte melden Sie sich erneut an.');
            return Response::redirect('/verwaltung/anmelden/', 303);
        }
        $result = $this->auth->verifySecondFactor($p['user'], $req->input('code'));
        if ($result !== 'ok') {
            $this->firewall?->record('2fa_failed', $req, 1, 'warning');
            if ($result === 'blocked') {
                Session::forget('auth_pending');
                Session::flash('auth_notice', 'Zu viele falsche Codes. Bitte melden Sie sich in einigen Minuten erneut an.');
                return Response::redirect('/verwaltung/anmelden/', 303);
            }
            return $this->render('code', ['error' => 'Der Code ist ungültig oder wurde schon verwendet.'], 422);
        }
        Session::forget('auth_pending');
        return $this->finishLogin($req, $p['user'], (string) $p['next']);
    }

    /** @param array<string,mixed> $user */
    private function finishLogin(Request $req, array $user, string $next): Response
    {
        Session::regenerate();
        $s = $this->auth->startSession($user, $req->ip(), $req->userAgent(), $req->cookie(self::deviceCookieName($req)));
        Session::set('admin_token', $s['token']);
        return Response::redirect(self::safeNext($next), 303)
            ->withCookie(self::deviceCookieName($req), $s['device'], ['expires' => time() + 400 * 86400, 'secure' => $req->isSecure(), 'samesite' => 'Strict']);
    }

    // ---- Zwei-Faktor einrichten ----

    public function setupForm(Request $req): Response
    {
        $p = $this->pending('auth_setup', self::SETUP_TTL);
        if ($p === null) {
            return Response::redirect('/verwaltung/anmelden/', 303);
        }
        $secret = isset($p['secret']) ? $this->crypto->decrypt((string) $p['secret'], 'setup') : $this->auth->newTotpSecret();
        $p['secret'] = $this->crypto->encrypt($secret, 'setup');
        unset($p['user']);
        Session::set('auth_setup', $p);
        $user = $this->auth->user((int) $p['uid']);
        return $this->render('setup', [
            'qr' => $this->auth->qrFor($secret, (string) $user['email']),
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'error' => null,
        ]);
    }

    public function setup(Request $req): Response
    {
        $p = $this->pending('auth_setup', self::SETUP_TTL);
        if ($p === null || !isset($p['secret'])) {
            return Response::redirect('/verwaltung/anmelden/', 303);
        }
        $secret = $this->crypto->decrypt((string) $p['secret'], 'setup');
        $codes = $this->auth->enableTotp((int) $p['uid'], $secret, $req->input('code'));
        if ($codes === null) {
            $user = $p['user'];
            return $this->render('setup', [
                'qr' => $this->auth->qrFor($secret, (string) $user['email']),
                'secret' => trim(chunk_split($secret, 4, ' ')),
                'error' => 'Der Code passt nicht. Prüfen Sie die Uhrzeit Ihres Telefons und geben Sie den aktuellen Code ein.',
            ], 422);
        }
        Session::forget('auth_setup');
        $user = $this->auth->user((int) $p['uid']);
        Session::set('show_codes', $codes);
        $resp = $this->finishLogin($req, $user, '/verwaltung/backup-codes/');
        return $resp;
    }

    public function showCodes(Request $req): Response
    {
        $codes = Session::get('show_codes');
        Session::forget('show_codes');
        if (!is_array($codes)) {
            return Response::redirect('/verwaltung/', 303);
        }
        return $this->render('codes', ['codes' => $codes]);
    }

    // ---- Einladung ----

    public function inviteForm(Request $req, string $token): Response
    {
        $row = $this->auth->validToken($token, 'invite');
        if ($row === null) {
            return $this->render('invalid_link', [], 410);
        }
        return $this->render('invite', ['name' => $row['name'], 'email' => $row['email'], 'role' => Roles::label((string) $row['role'])]);
    }

    public function acceptInvite(Request $req, string $token): Response
    {
        $row = $this->auth->validToken($token, 'invite');
        if ($row === null) {
            return $this->render('invalid_link', [], 410);
        }
        $name = mb_substr($req->input('name'), 0, 120);
        $pw = (string) ($req->post['password'] ?? '');
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Bitte Ihren Namen eingeben.';
        }
        $pwError = PasswordPolicy::check($pw, (string) $row['email']);
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        } elseif ($pw !== (string) ($req->post['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($errors !== []) {
            return $this->render('invite', ['name' => $name, 'email' => $row['email'], 'role' => Roles::label((string) $row['role']), 'errors' => $errors], 422);
        }
        $uid = $this->auth->acceptInvite($token, $name, $pw);
        if ($uid === null) {
            return $this->render('invalid_link', [], 410);
        }
        Session::regenerate();
        Session::set('auth_setup', ['uid' => $uid, 'at' => time(), 'next' => '/verwaltung/']);
        return Response::redirect('/verwaltung/einrichten/', 303);
    }

    // ---- Passwort vergessen ----

    public function forgotForm(Request $req): Response
    {
        return $this->render('forgot', ['sent' => false]);
    }

    public function forgot(Request $req): Response
    {
        $email = mb_substr($req->input('email'), 0, 190);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->auth->requestPasswordReset($email, $req->ip());
        }
        return $this->render('forgot', ['sent' => true]);
    }

    public function resetForm(Request $req, string $token): Response
    {
        $row = $this->auth->validToken($token, 'reset');
        return $row === null ? $this->render('invalid_link', [], 410) : $this->render('reset', []);
    }

    public function reset(Request $req, string $token): Response
    {
        $row = $this->auth->validToken($token, 'reset');
        if ($row === null) {
            return $this->render('invalid_link', [], 410);
        }
        $pw = (string) ($req->post['password'] ?? '');
        $error = PasswordPolicy::check($pw, (string) $row['email']);
        if ($error === null && $pw !== (string) ($req->post['password_confirm'] ?? '')) {
            $error = 'Die Passwörter stimmen nicht überein.';
        }
        if ($error !== null) {
            return $this->render('reset', ['error' => $error], 422);
        }
        $this->auth->resetPassword($token, $pw);
        Session::flash('auth_notice', 'Ihr Passwort wurde geändert. Bitte melden Sie sich an.');
        return Response::redirect('/verwaltung/anmelden/', 303);
    }

    // ---- Abmelden und Konto ----

    public function logout(Request $req, AdminContext $ctx): Response
    {
        $this->auth->logout((int) $ctx->session['id'], $ctx->id());
        Session::destroy();
        Session::start($req->isSecure(), 'admin');
        Session::flash('auth_notice', 'Sie wurden abgemeldet.');
        return Response::redirect('/verwaltung/anmelden/', 303);
    }

    public function account(Request $req, AdminContext $ctx, array $extra = [], int $status = 200): Response
    {
        return Response::html($this->view->render('admin/account.twig', $extra + [
            'title' => 'Mein Konto',
            'sessions' => $this->auth->sessionsOf($ctx->id()),
            'current_session' => (int) $ctx->session['id'],
            'codes_left' => $this->auth->backupCodesLeft($ctx->id()),
            'new_codes' => Session::flash('new_codes'),
            'errors' => [],
        ]), $status);
    }

    public function changePassword(Request $req, AdminContext $ctx): Response
    {
        $current = (string) ($req->post['current_password'] ?? '');
        $pw = (string) ($req->post['password'] ?? '');
        $errors = [];
        if (!password_verify($current, (string) ($this->auth->user($ctx->id())['password_hash'] ?? ''))) {
            $errors['current_password'] = 'Das aktuelle Passwort ist falsch.';
        } elseif (($e = PasswordPolicy::check($pw, (string) $ctx->user['email'])) !== null) {
            $errors['password'] = $e;
        } elseif ($pw !== (string) ($req->post['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'Die Passwörter stimmen nicht überein.';
        }
        if ($errors !== []) {
            return $this->account($req, $ctx, ['errors' => $errors], 422);
        }
        $this->auth->changePassword($ctx->id(), $pw, (int) $ctx->session['id']);
        AdminKernel::flash('Passwort geändert. Andere Sitzungen wurden abgemeldet.');
        return Response::redirect('/verwaltung/konto/', 303);
    }

    public function newCodes(Request $req, AdminContext $ctx): Response
    {
        if (!$this->auth->hasRecentReauth($ctx->session)) {
            return Response::redirect('/verwaltung/bestaetigen/?weiter=' . rawurlencode('/verwaltung/konto/#codes'), 303);
        }
        Session::flash('new_codes', $this->auth->regenerateBackupCodes($ctx->id()));
        return Response::redirect('/verwaltung/konto/#codes', 303);
    }

    public function revokeOthers(Request $req, AdminContext $ctx): Response
    {
        $n = $this->auth->revokeSessions($ctx->id(), (int) $ctx->session['id']);
        AdminKernel::flash($n === 1 ? 'Eine andere Sitzung wurde abgemeldet.' : "$n andere Sitzungen wurden abgemeldet.");
        return Response::redirect('/verwaltung/konto/', 303);
    }

    // ---- Erneute Bestätigung ----

    public function reauthForm(Request $req, AdminContext $ctx): Response
    {
        return Response::html($this->view->render('admin/reauth.twig', ['title' => 'Identität bestätigen', 'next' => self::safeNext($req->input('weiter')), 'error' => null]));
    }

    public function reauth(Request $req, AdminContext $ctx): Response
    {
        $next = self::safeNext($req->input('weiter'));
        if (!$this->auth->reauthenticate($ctx->user, (int) $ctx->session['id'], (string) ($req->post['password'] ?? ''), $req->input('code'))) {
            return Response::html($this->view->render('admin/reauth.twig', ['title' => 'Identität bestätigen', 'next' => $next, 'error' => 'Passwort oder Code ist falsch.']), 422);
        }
        AdminKernel::flash('Bestätigt. Sie können die Aktion jetzt ' . AuthService::REAUTH_MINUTES . ' Minuten lang ausführen.');
        return Response::redirect($next, 303);
    }
}
