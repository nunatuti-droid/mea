<?php
declare(strict_types=1);

namespace Mea\Core;

/** Sitzung nur bei Bedarf (Formular, Verwaltung), damit öffentliche Seiten cachebar bleiben. */
final class Session
{
    public static function start(bool $secure, string $scope = 'public'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $base = $scope === 'admin' ? 'mea-admin' : 'mea';
        session_name($secure ? '__Host-' . $base : $base);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $scope === 'admin' ? 'Strict' : 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '43200');
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Neue Sitzungs-ID nach Anmeldung oder Rechteänderung. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => $p['samesite']]);
            session_destroy();
        }
    }

    /** Einmal-Nachricht für die nächste Seite. */
    public static function flash(string $key, mixed $value = null): mixed
    {
        if (func_num_args() === 2) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
}
