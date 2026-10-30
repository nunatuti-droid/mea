<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

/**
 * Geheimer Update-Schlüssel, mit einer Passphrase verschlüsselt (libsodium: Argon2id + XSalsa20-Poly1305).
 * Die Passphrase kommt nur aus der Umgebungsvariable MEA_RELEASE_PASSPHRASE, nie aus Dateien oder dem Chat.
 */
final class SealedKey
{
    public const ENV = 'MEA_RELEASE_PASSPHRASE';

    public static function passphrase(): string
    {
        $p = (string) getenv(self::ENV);
        if (mb_strlen($p) < 20) {
            throw new \RuntimeException(self::ENV . ' fehlt oder ist kürzer als 20 Zeichen.');
        }
        return $p;
    }

    public static function seal(string $secret, string $passphrase): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        return json_encode(['v' => 1, 'salt' => base64_encode($salt), 'nonce' => base64_encode($nonce),
            'ct' => base64_encode(sodium_crypto_secretbox($secret, $nonce, $key))], JSON_PRETTY_PRINT) . "\n";
    }

    public static function open(string $sealed, string $passphrase): string
    {
        $d = json_decode($sealed, true);
        if (!is_array($d) || ($d['v'] ?? 0) !== 1) {
            throw new \RuntimeException('Unbekanntes Format des Schlüssels.');
        }
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, (string) base64_decode((string) $d['salt'], true),
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $plain = sodium_crypto_secretbox_open((string) base64_decode((string) $d['ct'], true), (string) base64_decode((string) $d['nonce'], true), $key);
        if ($plain === false) {
            throw new \RuntimeException('Passphrase falsch oder Schlüsseldatei beschädigt.');
        }
        return $plain;
    }

    /** Schlüssel aus Datei lesen: *.sealed wird mit der Passphrase geöffnet, sonst Klartext (Entwicklungsrechner). */
    public static function load(string $file): string
    {
        $raw = trim((string) file_get_contents($file));
        return str_ends_with($file, '.sealed') ? trim(self::open($raw, self::passphrase())) : $raw;
    }
}
