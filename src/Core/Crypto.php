<?php
declare(strict_types=1);

namespace Mea\Core;

/**
 * Verschlüsselung und Hashes mit libsodium bzw. HMAC-SHA256 (keine eigenen Verfahren).
 * Jeder Zweck bekommt einen eigenen, aus APP_KEY abgeleiteten Schlüssel.
 */
final class Crypto
{
    private string $master;

    public function __construct(string $appKey)
    {
        $raw = base64_decode($appKey, true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('APP_KEY fehlt oder ist zu kurz (32 Byte, base64).');
        }
        $this->master = $raw;
    }

    public static function fromConfig(Config $c): self
    {
        return new self($c->require('APP_KEY'));
    }

    private function key(string $context): string
    {
        return sodium_crypto_generichash('mea:' . $context, $this->master, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($context)));
    }

    public function decrypt(string $encoded, string $context): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Ungültige verschlüsselte Daten.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key($context),
        );
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        return $plain;
    }

    /** Schlüsselgebundener Hash, z. B. für Tokens, Backup-Codes und IP-Adressen. */
    public function hash(string $value, string $context): string
    {
        return hash_hmac('sha256', $value, $this->key('hmac:' . $context));
    }

    /** Zufälliges Token für URLs (256 Bit). */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
