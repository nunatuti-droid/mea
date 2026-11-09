<?php
declare(strict_types=1);

namespace Mea\Module\Auth;

final class PasswordPolicy
{
    private const COMMON = ['passwort1234', 'password1234', '123456789012', 'qwertzuiop12', 'meabildung123', 'willkommen123', 'sommer2026!!', 'admin1234567'];

    /** @return string|null Fehlermeldung oder null */
    public static function check(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if (mb_strlen($password) > 200) {
            return 'Das Passwort darf höchstens 200 Zeichen lang sein.';
        }
        $local = strtolower((string) strstr($email, '@', true));
        if ($local !== '' && strlen($local) >= 4 && str_contains(strtolower($password), $local)) {
            return 'Das Passwort darf Ihre E-Mail-Adresse nicht enthalten.';
        }
        if (in_array(strtolower($password), self::COMMON, true) || count(array_unique(mb_str_split($password))) < 5) {
            return 'Dieses Passwort ist zu leicht zu erraten.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }
}
