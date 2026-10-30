<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

/**
 * Verschlüsselt Sicherungsdateien in Blöcken (libsodium secretstream, XChaCha20-Poly1305).
 * Jede Veränderung oder ein abgeschnittenes Ende fällt beim Entschlüsseln auf.
 */
final class BackupCrypto
{
    private const MAGIC = "MEABAK1\n";
    private const CHUNK = 1048576;

    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new \InvalidArgumentException('Ungültiger Sicherungsschlüssel.');
        }
    }

    public function encryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new \RuntimeException('Sicherungsdatei kann nicht geschrieben werden.');
        }
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
        fwrite($dst, self::MAGIC . $header);
        $size = filesize($in) ?: 0;
        $done = 0;
        do {
            $chunk = (string) fread($src, self::CHUNK);
            $done += strlen($chunk);
            $tag = $done >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $enc = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($dst, pack('N', strlen($enc)) . $enc);
        } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        fclose($src);
        fclose($dst);
    }

    /** @throws \RuntimeException bei falschem Schlüssel, Manipulation oder unvollständiger Datei */
    public function decryptFile(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        if ($src === false || $dst === false) {
            throw new \RuntimeException('Sicherungsdatei kann nicht gelesen werden.');
        }
        try {
            if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new \RuntimeException('Keine Sicherungsdatei dieser Website.');
            }
            $header = (string) fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $final = false;
            while (!$final) {
                $len = fread($src, 4);
                if ($len === false || strlen($len) !== 4) {
                    throw new \RuntimeException('Die Sicherungsdatei ist unvollständig.');
                }
                $n = unpack('N', $len)[1];
                if ($n > self::CHUNK + 64) {
                    throw new \RuntimeException('Die Sicherungsdatei ist beschädigt.');
                }
                $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, (string) fread($src, $n));
                if ($res === false) {
                    throw new \RuntimeException('Die Sicherung ist beschädigt, verändert oder mit einem anderen Schlüssel erstellt.');
                }
                fwrite($dst, $res[0]);
                $final = $res[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (!feof($src) && fread($src, 1) !== '') {
                throw new \RuntimeException('Die Sicherungsdatei enthält unerwartete Daten.');
            }
        } finally {
            fclose($src);
            fclose($dst);
        }
    }
}
