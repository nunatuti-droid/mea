<?php
declare(strict_types=1);

namespace Mea\Module\Auth;

use Mea\Core\Crypto;
use Mea\Core\Database;
use Mea\Module\Audit\AuditLog;
use Mea\Module\Inquiries\RateLimiter;
use Mea\Module\Mail\Mailer;
use Psr\Clock\ClockInterface;

/**
 * Anmeldung mit Passwort (Argon2id) und verpflichtendem zweitem Faktor (TOTP oder Backup-Code),
 * Sperre nach Fehlversuchen pro Konto und pro IP, widerrufbare Sitzungen,
 * Einladungen (48 h) und Passwort-Zurücksetzen (60 min).
 */
final class AuthService
{
    public const MAX_FAILED = 5;
    public const LOCK_MINUTES = 15;
    public const IDLE_MINUTES = 30;
    public const ABSOLUTE_HOURS = 12;
    public const REAUTH_MINUTES = 10;
    public const INVITE_HOURS = 48;
    public const RESET_MINUTES = 60;
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$OVRlRk9qaExmY1VSN0x0Yg$sBiVlfmbrdxzH29NoAEq0oHHNaKfoLAzN9w1Lfpf4gE';

    public function __construct(
        private Database $db,
        private Crypto $crypto,
        private Totp $totp,
        private RateLimiter $limiter,
        private Mailer $mailer,
        private AuditLog $audit,
        private ClockInterface $clock,
    ) {}

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function nowTs(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** @return array<string,mixed>|null */
    public function user(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /**
     * Erster Schritt: E-Mail und Passwort.
     * @return array{status:'ok'|'invalid'|'blocked', user?:array<string,mixed>}
     */
    public function attemptPassword(string $email, string $password, string $ip): array
    {
        if (!$this->limiter->hit('login-ip', $ip, 30, 900)) {
            return ['status' => 'blocked'];
        }
        $user = $this->db->one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        $hash = $user['password_hash'] ?? null;
        $ok = password_verify($password, is_string($hash) ? $hash : self::DUMMY_HASH);
        if ($user === null || !is_string($hash)) {
            return ['status' => 'invalid'];
        }
        if ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > $this->nowTs()) {
            return ['status' => 'blocked'];
        }
        if (!$ok || $user['status'] !== 'active') {
            if ($user['status'] === 'active') {
                $this->registerFailure($user);
            }
            return ['status' => 'invalid'];
        }
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [PasswordPolicy::hash($password), $user['id']]);
        }
        return ['status' => 'ok', 'user' => $user];
    }

    /** @param array<string,mixed> $user */
    private function registerFailure(array $user): void
    {
        $failed = (int) $user['failed_logins'] + 1;
        if ($failed >= self::MAX_FAILED) {
            $until = $this->clock->now()->modify('+' . self::LOCK_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $this->db->run('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?', [$until, $user['id']]);
            $this->audit->record('auth.locked', 'Konto nach Fehlversuchen vorübergehend gesperrt', 'user', (int) $user['id'], [], (int) $user['id']);
            $this->securityMail($user, 'Ihr Konto wurde nach mehreren fehlgeschlagenen Anmeldeversuchen für ' . self::LOCK_MINUTES . ' Minuten gesperrt. Wenn Sie das nicht waren, ändern Sie bitte Ihr Passwort.', 'lock-' . $user['id'] . '-' . $until);
        } else {
            $this->db->run('UPDATE users SET failed_logins = ? WHERE id = ?', [$failed, $user['id']]);
        }
    }

    /**
     * Zweiter Schritt: TOTP-Code oder Backup-Code.
     * @param array<string,mixed> $user
     * @return 'ok'|'invalid'|'blocked'
     */
    public function verifySecondFactor(array $user, string $code): string
    {
        $uid = (int) $user['id'];
        if (!$this->limiter->hit('2fa-user', (string) $uid, 5, 300)) {
            return 'blocked';
        }
        $code = trim($code);
        if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
            $fresh = $this->user($uid);
            if ($fresh === null || $fresh['totp_secret_enc'] === null) {
                return 'invalid';
            }
            $secret = $this->crypto->decrypt((string) $fresh['totp_secret_enc'], 'totp');
            $step = $this->totp->verify($secret, $code, $fresh['totp_last_step'] !== null ? (int) $fresh['totp_last_step'] : null);
            if ($step === null) {
                return 'invalid';
            }
            // atomar: nur ein Request darf diesen Zeitschritt verbrauchen
            $n = $this->db->run('UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)', [$step, $uid, $step]);
            return $n === 1 ? 'ok' : 'invalid';
        }
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
        if (strlen($normalized) !== 10) {
            return 'invalid';
        }
        $hash = $this->crypto->hash($normalized, 'backup');
        $n = $this->db->run('UPDATE user_backup_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$this->now(), $uid, $hash]);
        if ($n === 1) {
            $this->audit->record('auth.backup_code_used', 'Backup-Code verwendet', 'user', $uid, [], $uid);
            $left = (int) $this->db->value('SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL', [$uid]);
            if ($left <= 2) {
                $this->securityMail($user, "Sie haben einen Backup-Code verwendet. Es sind noch $left Codes übrig. Bitte erzeugen Sie neue Codes unter „Mein Konto“.", 'backup-low-' . $uid . '-' . $left);
            }
            return 'ok';
        }
        return 'invalid';
    }

    /**
     * Vollständige Anmeldung: Sitzung anlegen, Gerät prüfen.
     * @param array<string,mixed> $user
     * @return array{token:string, device:string}
     */
    public function startSession(array $user, string $ip, string $userAgent, string $deviceCookie): array
    {
        $uid = (int) $user['id'];
        $token = Crypto::token();
        $now = $this->now();
        $this->db->insert('user_sessions', [
            'user_id' => $uid, 'token_hash' => $this->crypto->hash($token, 'session'),
            'ip_hash' => $this->crypto->hash($ip, 'ip'), 'user_agent' => mb_substr($userAgent, 0, 255),
            'created_at' => $now, 'last_seen_at' => $now,
        ]);
        $device = preg_match('/^[A-Za-z0-9_-]{43}$/', $deviceCookie) ? $deviceCookie : Crypto::token();
        $dHash = $this->crypto->hash($device, 'device');
        $known = $this->db->value('SELECT 1 FROM user_devices WHERE user_id = ? AND device_hash = ?', [$uid, $dHash]) !== null;
        $hadDevices = $this->db->value('SELECT 1 FROM user_devices WHERE user_id = ? LIMIT 1', [$uid]) !== null;
        if ($known) {
            $this->db->run('UPDATE user_devices SET last_seen_at = ? WHERE user_id = ? AND device_hash = ?', [$now, $uid, $dHash]);
        } else {
            $this->db->insert('user_devices', ['user_id' => $uid, 'device_hash' => $dHash, 'first_seen_at' => $now, 'last_seen_at' => $now]);
            if ($hadDevices) {
                $this->securityMail($user, 'Es gab eine Anmeldung von einem neuen Gerät oder Browser (' . self::browserLabel($userAgent) . ', ' . $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i') . ' Uhr). Wenn Sie das nicht waren, ändern Sie sofort Ihr Passwort und melden Sie alle Sitzungen ab.', 'newdev-' . $uid . '-' . $dHash);
            }
        }
        $this->db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [$now, $uid]);
        $this->audit->record('auth.login', 'Anmeldung', 'user', $uid, [], $uid);
        return ['token' => $token, 'device' => $device];
    }

    /**
     * Prüft die Sitzung bei jeder Anfrage (Widerruf, Inaktivität, absolute Dauer).
     * @return array{user:array<string,mixed>, session:array<string,mixed>}|null
     */
    public function resolveSession(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $s = $this->db->one('SELECT * FROM user_sessions WHERE token_hash = ?', [$this->crypto->hash($token, 'session')]);
        if ($s === null || $s['revoked_at'] !== null) {
            return null;
        }
        $now = $this->nowTs();
        $idle = $now - (int) strtotime($s['last_seen_at'] . ' UTC') > self::IDLE_MINUTES * 60;
        $old = $now - (int) strtotime($s['created_at'] . ' UTC') > self::ABSOLUTE_HOURS * 3600;
        if ($idle || $old) {
            $this->db->run('UPDATE user_sessions SET revoked_at = ? WHERE id = ?', [$this->now(), $s['id']]);
            return null;
        }
        $user = $this->user((int) $s['user_id']);
        if ($user === null || $user['status'] !== 'active' || $user['totp_enabled_at'] === null) {
            return null;
        }
        if ($now - (int) strtotime($s['last_seen_at'] . ' UTC') > 60) {
            $this->db->run('UPDATE user_sessions SET last_seen_at = ? WHERE id = ?', [$this->now(), $s['id']]);
        }
        return ['user' => $user, 'session' => $s];
    }

    public function logout(int $sessionId, int $userId): void
    {
        $this->db->run('UPDATE user_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [$this->now(), $sessionId]);
        $this->audit->record('auth.logout', 'Abmeldung', 'user', $userId, [], $userId);
    }

    public function revokeSessions(int $userId, ?int $exceptSessionId = null): int
    {
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [$this->now(), $userId, $exceptSessionId ?? 0],
        );
    }

    /** Erneute Bestätigung mit Passwort und aktuellem Code für kritische Aktionen. */
    public function reauthenticate(array $user, int $sessionId, string $password, string $code): bool
    {
        $fresh = $this->user((int) $user['id']);
        if ($fresh === null || !password_verify($password, (string) $fresh['password_hash'])) {
            $this->audit->record('auth.reauth_failed', 'Bestätigung fehlgeschlagen', 'user', (int) $user['id']);
            return false;
        }
        if ($this->verifySecondFactor($fresh, $code) !== 'ok') {
            return false;
        }
        $this->db->run('UPDATE user_sessions SET reauth_at = ? WHERE id = ?', [$this->now(), $sessionId]);
        $this->audit->record('auth.reauth', 'Identität für kritische Aktion bestätigt', 'user', (int) $user['id']);
        return true;
    }

    /** @param array<string,mixed> $session */
    public function hasRecentReauth(array $session): bool
    {
        return $session['reauth_at'] !== null
            && $this->nowTs() - (int) strtotime($session['reauth_at'] . ' UTC') <= self::REAUTH_MINUTES * 60;
    }

    /** @return list<array<string,mixed>> aktive Sitzungen eines Benutzers */
    public function sessionsOf(int $userId): array
    {
        $since = $this->clock->now()->modify('-' . self::IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        return $this->db->all('SELECT id, user_agent, created_at, last_seen_at FROM user_sessions
            WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at > ? ORDER BY last_seen_at DESC', [$userId, $since]);
    }

    public function backupCodesLeft(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    // ---- Zwei-Faktor einrichten ----

    public function newTotpSecret(): string
    {
        return $this->totp->newSecret();
    }

    public function qrFor(string $secret, string $email): string
    {
        return $this->totp->qrDataUri($this->totp->provisioningUri($secret, $email, 'MEA Verwaltung'));
    }

    /**
     * Bestätigt die Einrichtung mit einem ersten Code und aktiviert das Konto.
     * @return list<string>|null Backup-Codes (nur einmal sichtbar) oder null bei falschem Code
     */
    public function enableTotp(int $userId, string $secret, string $code): ?array
    {
        if (!$this->limiter->hit('2fa-setup', (string) $userId, 10, 600)) {
            return null;
        }
        $step = $this->totp->verify($secret, $code, null);
        if ($step === null) {
            return null;
        }
        $this->db->run(
            "UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = ?, status = IF(status = 'invited', 'active', status) WHERE id = ?",
            [$this->crypto->encrypt($secret, 'totp'), $this->now(), $step, $userId],
        );
        $this->audit->record('auth.2fa_enabled', 'Zwei-Faktor-Anmeldung eingerichtet', 'user', $userId, [], $userId);
        return $this->regenerateBackupCodes($userId);
    }

    /** @return list<string> */
    public function regenerateBackupCodes(int $userId): array
    {
        $this->db->run('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        for ($i = 0; $i < 10; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $this->db->insert('user_backup_codes', ['user_id' => $userId, 'code_hash' => $this->crypto->hash($c, 'backup')]);
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        $this->audit->record('auth.backup_codes', 'Neue Backup-Codes erzeugt', 'user', $userId);
        return $codes;
    }

    /** Zwei-Faktor zurücksetzen (durch Administrator, z. B. bei Geräteverlust). */
    public function resetTotp(int $userId): void
    {
        $this->db->run('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?', [$userId]);
        $this->db->run('DELETE FROM user_backup_codes WHERE user_id = ?', [$userId]);
        $this->revokeSessions($userId);
    }

    // ---- Einladungen und Passwort ----

    /** @return array{user_id:int, token:string} */
    public function invite(string $email, string $name, string $role, ?int $byUserId): array
    {
        $email = mb_strtolower(trim($email));
        $existing = $this->db->one('SELECT * FROM users WHERE email = ?', [$email]);
        if ($existing !== null && $existing['status'] !== 'invited') {
            throw new \DomainException('Für diese E-Mail-Adresse gibt es bereits ein Konto.');
        }
        $uid = $existing !== null ? (int) $existing['id'] : $this->db->insert('users', [
            'email' => $email, 'name' => $name, 'role' => $role, 'status' => 'invited', 'created_by' => $byUserId,
        ]);
        if ($existing !== null) {
            $this->db->run('UPDATE users SET name = ?, role = ? WHERE id = ?', [$name, $role, $uid]);
        }
        $this->db->run("UPDATE user_tokens SET revoked_at = ? WHERE user_id = ? AND type = 'invite' AND used_at IS NULL AND revoked_at IS NULL", [$this->now(), $uid]);
        $token = Crypto::token();
        $this->db->insert('user_tokens', [
            'user_id' => $uid, 'type' => 'invite', 'token_hash' => $this->crypto->hash($token, 'usertoken'),
            'expires_at' => $this->clock->now()->modify('+' . self::INVITE_HOURS . ' hours')->format('Y-m-d H:i:s'),
            'created_by' => $byUserId,
        ]);
        $this->mailer->queue('user_invite', $email, $name, [
            'name' => $name, 'rolle' => Roles::label($role),
            'link' => $this->mailer->url('/verwaltung/einladung/' . $token . '/'), 'gueltig_stunden' => self::INVITE_HOURS,
        ], 'invite-' . $this->crypto->hash($token, 'mailkey'), 'user', $uid);
        $this->audit->record('user.invited', "Einladung an $email (" . Roles::label($role) . ')', 'user', $uid);
        return ['user_id' => $uid, 'token' => $token];
    }

    public function revokeInvites(int $userId): void
    {
        $this->db->run("UPDATE user_tokens SET revoked_at = ? WHERE user_id = ? AND type = 'invite' AND used_at IS NULL AND revoked_at IS NULL", [$this->now(), $userId]);
    }

    /** @return array<string,mixed>|null Token-Datensatz mit Benutzer, wenn gültig */
    public function validToken(string $token, string $type): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        return $this->db->one(
            'SELECT t.id AS token_id, u.* FROM user_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.type = ? AND t.used_at IS NULL AND t.revoked_at IS NULL AND t.expires_at > ?',
            [$this->crypto->hash($token, 'usertoken'), $type, $this->now()],
        );
    }

    /** Einladung annehmen: Name und eigenes Passwort; danach muss 2FA eingerichtet werden. */
    public function acceptInvite(string $token, string $name, string $password): ?int
    {
        $row = $this->validToken($token, 'invite');
        if ($row === null) {
            return null;
        }
        return $this->db->transaction(function () use ($row, $name, $password): ?int {
            $n = $this->db->run('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [$this->now(), $row['token_id']]);
            if ($n !== 1) {
                return null;
            }
            $this->db->run('UPDATE users SET name = ?, password_hash = ?, password_changed_at = ? WHERE id = ?', [
                $name, PasswordPolicy::hash($password), $this->now(), $row['id'],
            ]);
            $this->audit->record('user.invite_accepted', 'Einladung angenommen', 'user', (int) $row['id'], [], (int) $row['id']);
            return (int) $row['id'];
        });
    }

    /** Immer dieselbe Rückmeldung, egal ob die Adresse existiert. */
    public function requestPasswordReset(string $email, string $ip): void
    {
        if (!$this->limiter->hit('reset-ip', $ip, 5, 3600)) {
            return;
        }
        $user = $this->db->one("SELECT * FROM users WHERE email = ? AND status = 'active'", [mb_strtolower(trim($email))]);
        if ($user === null || !$this->limiter->hit('reset-user', (string) $user['id'], 3, 3600)) {
            return;
        }
        $token = Crypto::token();
        $this->db->insert('user_tokens', [
            'user_id' => $user['id'], 'type' => 'reset', 'token_hash' => $this->crypto->hash($token, 'usertoken'),
            'expires_at' => $this->clock->now()->modify('+' . self::RESET_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
        ]);
        $this->mailer->queue('password_reset', (string) $user['email'], (string) $user['name'], [
            'name' => $user['name'], 'link' => $this->mailer->url('/verwaltung/passwort/neu/' . $token . '/'),
            'gueltig_minuten' => self::RESET_MINUTES,
        ], 'reset-' . $this->crypto->hash($token, 'mailkey'), 'user', (int) $user['id']);
        $this->audit->record('auth.reset_requested', 'Passwort-Zurücksetzen angefordert', 'user', (int) $user['id'], [], (int) $user['id']);
    }

    public function resetPassword(string $token, string $password): bool
    {
        $row = $this->validToken($token, 'reset');
        if ($row === null) {
            return false;
        }
        $n = $this->db->run('UPDATE user_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [$this->now(), $row['token_id']]);
        if ($n !== 1) {
            return false;
        }
        $this->changePassword((int) $row['id'], $password);
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$row['id']]);
        $this->securityMail($row, 'Ihr Passwort wurde geändert. Alle Sitzungen wurden abgemeldet. Wenn Sie das nicht waren, wenden Sie sich sofort an einen Administrator.', 'pwchanged-' . $row['id'] . '-' . $row['token_id']);
        return true;
    }

    public function changePassword(int $userId, string $password, ?int $keepSessionId = null): void
    {
        $this->db->run('UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?', [PasswordPolicy::hash($password), $this->now(), $userId]);
        $this->revokeSessions($userId, $keepSessionId);
        $this->audit->record('auth.password_changed', 'Passwort geändert', 'user', $userId);
    }

    /** Schutz des letzten aktiven Administrators. */
    public function isLastActiveAdmin(int $userId): bool
    {
        $u = $this->user($userId);
        if ($u === null || $u['role'] !== 'admin' || $u['status'] !== 'active') {
            return false;
        }
        return (int) $this->db->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'") <= 1;
    }

    /** @param array<string,mixed> $user */
    private function securityMail(array $user, string $text, string $key): void
    {
        $this->mailer->queue('security_notice', (string) $user['email'], (string) $user['name'], [
            'name' => $user['name'], 'hinweis' => $text,
        ], 'sec-' . $key, 'user', (int) $user['id']);
    }

    private static function browserLabel(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unbekanntes System',
        };
        return "$browser auf $os";
    }
}
