<?php
declare(strict_types=1);

namespace Mea\Module\Security;

/** Hilfen für IP-Adressen: CIDR-Vergleich und gekürzte Anzeige (Datensparsamkeit). */
final class IpAddress
{
    public static function valid(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** Gültige Angabe wie 203.0.113.7, 203.0.113.0/24 oder 2001:db8::/48? */
    public static function validCidr(string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        if (!self::valid((string) $net)) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains((string) $net, ':') ? 128 : 32;
        return ctype_digit($bits) && (int) $bits >= 8 && (int) $bits <= $max;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton((string) $net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** Gekürzte Adresse für Protokolle: 203.0.113.x bzw. die ersten drei IPv6-Blöcke. */
    public static function mask(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.x', $ip) ?? 'x';
        }
        $full = @inet_pton($ip);
        if ($full === false) {
            return 'unbekannt';
        }
        $groups = str_split(bin2hex($full), 4);
        return implode(':', array_slice($groups, 0, 3)) . ':…';
    }
}
