<?php
declare(strict_types=1);

namespace Mea\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t)) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function check(string $given): bool
    {
        $t = Session::get('_csrf');
        return is_string($t) && $given !== '' && hash_equals($t, $given);
    }
}
