<?php
declare(strict_types=1);

namespace Mea\Module\Security;

/**
 * Dateiwächter: vergleicht die Programmdateien mit einer signierten Referenz (integrity.json
 * plus Ed25519-Signatur integrity.sig). Der geheime Signaturschlüssel liegt nie auf dem Server;
 * der Server kennt nur den öffentlichen Schlüssel (RELEASE_PUBLIC_KEY). Wer Dateien ändert,
 * kann deshalb keine passende Referenz erzeugen.
 */
final class FileGuard
{
    /** Geschützte Bereiche relativ zum Projektordner */
    public const SCOPE = ['src', 'templates', 'bin', 'migrations', 'public', 'vendor', 'composer.json', 'composer.lock', 'phinx.php', 'VERSION'];
    /** Ausgenommen: erzeugte Inhalte und Daten */
    public const EXCLUDE = ['public/media/'];
    /** Im Upload-Ordner erlaubte Dateiendungen */
    public const MEDIA_EXT = ['webp', 'pdf'];

    public function __construct(private string $root, private ?string $publicKeyB64) {}

    /** @return array<string,string> Pfad => SHA-256 */
    public function scan(): array
    {
        $out = [];
        foreach (self::SCOPE as $entry) {
            $path = $this->root . '/' . $entry;
            if (is_file($path)) {
                $out[$entry] = (string) hash_file('sha256', $path);
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($this->root) + 1);
                $rel = str_replace('\\', '/', $rel);
                foreach (self::EXCLUDE as $ex) {
                    if (str_starts_with($rel, $ex)) {
                        continue 2;
                    }
                }
                if (str_contains('/' . $rel, '/.git/')) {
                    continue; // Versionsverwaltung (nur auf Entwicklungsrechnern)
                }
                $out[$rel] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($out);
        return $out;
    }

    /** Referenz erstellen und mit dem geheimen Schlüssel signieren (nur auf dem Entwicklungsrechner). */
    public function writeSignedManifest(string $secretKeyB64, string $version): void
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Ungültiger geheimer Schlüssel.');
        }
        $manifest = json_encode(['version' => $version, 'created_at' => gmdate('c'), 'files' => $this->scan()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->root . '/integrity.json', $manifest);
        file_put_contents($this->root . '/integrity.sig', base64_encode(sodium_crypto_sign_detached((string) $manifest, $sk)));
    }

    /**
     * @return array{status:'ok'|'changed'|'no_reference'|'invalid_reference', version:?string, checked:int,
     *   changed:list<string>, missing:list<string>, unexpected:list<string>}
     */
    public function check(): array
    {
        $result = ['status' => 'no_reference', 'version' => null, 'checked' => 0, 'changed' => [], 'missing' => [], 'unexpected' => []];
        $manifestFile = $this->root . '/integrity.json';
        $sigFile = $this->root . '/integrity.sig';
        $current = $this->scan();
        $result['unexpected'] = $this->unexpectedMedia();
        if (!is_file($manifestFile) || !is_file($sigFile) || $this->publicKeyB64 === null || $this->publicKeyB64 === '') {
            $result['checked'] = count($current);
            return $result;
        }
        $manifest = (string) file_get_contents($manifestFile);
        $sig = base64_decode(trim((string) file_get_contents($sigFile)), true);
        $pk = base64_decode($this->publicKeyB64, true);
        if ($sig === false || $pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $manifest, $pk)) {
            $result['status'] = 'invalid_reference';
            return $result;
        }
        $ref = json_decode($manifest, true);
        $files = is_array($ref['files'] ?? null) ? $ref['files'] : [];
        $result['version'] = is_string($ref['version'] ?? null) ? $ref['version'] : null;
        foreach ($files as $path => $hash) {
            if (!isset($current[$path])) {
                $result['missing'][] = (string) $path;
            } elseif (!hash_equals((string) $hash, $current[$path])) {
                $result['changed'][] = (string) $path;
            }
        }
        foreach (array_keys($current) as $path) {
            if (!isset($files[$path]) && $path !== 'integrity.json' && $path !== 'integrity.sig') {
                $result['unexpected'][] = $path;
            }
        }
        $result['checked'] = count($files);
        $result['status'] = ($result['changed'] || $result['missing'] || $result['unexpected']) ? 'changed' : 'ok';
        return $result;
    }

    /** Dateien im Upload-Ordner, die dort nicht hingehören (z. B. Skripte). @return list<string> */
    private function unexpectedMedia(): array
    {
        $dir = $this->root . '/public/media';
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && !in_array(strtolower($file->getExtension()), self::MEDIA_EXT, true)) {
                $out[] = 'public/media/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }
        return $out;
    }

    /** Fingerabdruck des öffentlichen Schlüssels zum Abgleich (z. B. mit der Doku). */
    public function keyFingerprint(): ?string
    {
        $pk = $this->publicKeyB64 ? base64_decode($this->publicKeyB64, true) : false;
        return $pk === false || $pk === '' ? null : substr(hash('sha256', $pk), 0, 16);
    }

    /** @return array{public:string, secret:string} base64 */
    public static function generateKeyPair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return ['public' => base64_encode(sodium_crypto_sign_publickey($kp)), 'secret' => base64_encode(sodium_crypto_sign_secretkey($kp))];
    }
}
