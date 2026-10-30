<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Core\App;
use Mea\Core\Database;
use Mea\Module\Security\FileGuard;
use Psr\Log\LoggerInterface;

/**
 * Kontrollierte Updates aus signierten Paketen.
 *
 * Paket (ZIP): update.json (Version, Voraussetzungen, Änderungen, SHA-256 je Datei),
 * update.sig (Ed25519-Signatur über update.json) und files/… mit dem vollständigen Programmstand.
 * Geprüft wird gegen den öffentlichen Schlüssel aus der Serverkonfiguration, nie gegen
 * eine Prüfsumme aus dem Paket selbst.
 */
final class UpdateService
{
    /** Erlaubte Zielbereiche; Daten, Uploads und Zugangsdaten werden nie überschrieben */
    private const TARGETS = '#^(src|templates|bin|migrations|vendor|public/(assets|index\.php|\.htaccess|robots\.txt|favicon[^/]*))(/|$)|^(composer\.json|composer\.lock|phinx\.php|VERSION|integrity\.json|integrity\.sig)$#';

    /** @var (\Closure(): list<string>)|null */
    private ?\Closure $selfTest = null;

    public function __construct(
        private Database $db,
        private string $root,
        private ?string $publicKeyB64,
        private BackupService $backups,
        private LoggerInterface $log,
        private string $currentVersion,
    ) {}

    /** Für Tests: eigene Selbsttests einsetzen. @param \Closure(): list<string> $fn Liste von Fehlern */
    public function useSelfTest(\Closure $fn): void
    {
        $this->selfTest = $fn;
    }

    /**
     * Paket prüfen und Informationen liefern (noch nichts installieren).
     * @return array{version:string, from:string, changelog:string, files:int, requires:array<string,string>, problems:list<string>, sha256:string}
     */
    public function inspect(string $package): array
    {
        [$meta] = $this->open($package);
        $problems = [];
        $version = (string) $meta['version'];
        if (version_compare($version, $this->currentVersion, '<=')) {
            $problems[] = "Version $version ist nicht neuer als die installierte Version {$this->currentVersion}.";
        }
        $min = (string) ($meta['requires']['min_version'] ?? '0.0.0');
        if (version_compare($this->currentVersion, $min, '<')) {
            $problems[] = "Dieses Update setzt mindestens Version $min voraus.";
        }
        $php = (string) ($meta['requires']['php'] ?? '8.3.0');
        if (version_compare(PHP_VERSION, $php, '<')) {
            $problems[] = "PHP $php oder neuer ist nötig (installiert: " . PHP_VERSION . ').';
        }
        foreach ((array) ($meta['requires']['extensions'] ?? []) as $ext) {
            if (!extension_loaded((string) $ext)) {
                $problems[] = "Die PHP-Erweiterung $ext fehlt.";
            }
        }
        if ($this->db->value("SELECT 1 FROM app_updates WHERE version = ? AND status = 'installed'", [$version]) !== null) {
            $problems[] = "Version $version ist bereits installiert.";
        }
        return [
            'version' => $version, 'from' => $this->currentVersion, 'changelog' => (string) ($meta['changelog'] ?? ''),
            'files' => count((array) $meta['files']), 'requires' => ['min_version' => $min, 'php' => $php],
            'problems' => $problems, 'sha256' => (string) hash_file('sha256', $package),
        ];
    }

    /**
     * Installation mit Sicherung, Wartungsmodus, Migrationen, Selbsttest und automatischem Rückfall.
     * @return array{id:int, status:string, log:list<string>}
     */
    public function install(string $package, ?int $userId): array
    {
        self::keepRunning();
        $lock = $this->backups->lock();
        $log = [];
        $flag = App::maintenanceFlag($this->root);
        $info = $this->inspect($package);
        if ($info['problems'] !== []) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw new UpdateException(implode(' ', $info['problems']));
        }
        $id = $this->db->insert('app_updates', ['version' => $info['version'], 'from_version' => $this->currentVersion, 'status' => 'running',
            'package_sha256' => $info['sha256'], 'changelog' => mb_substr($info['changelog'], 0, 20000), 'installed_by' => $userId]);
        $rollbackDir = $this->root . '/storage/updates/rollback-' . $id;
        $backupId = null;
        try {
            [$meta, $zip] = $this->open($package);
            $backupId = $this->backups->create('pre_update', $userId, 'Vor Update auf ' . $info['version']);
            $this->db->run('UPDATE app_updates SET backup_id = ? WHERE id = ?', [$backupId, $id]);
            $log[] = "Sicherung #$backupId erstellt.";
            file_put_contents($flag, 'update');
            $log[] = 'Wartungsmodus aktiv.';
            $this->installFiles($zip, $meta, $rollbackDir, $log);
            $zip->close();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $out = (new Migrator($this->db->pdo(), $this->root . '/migrations'))->migrate();
            $log[] = 'Datenbank aktualisiert.' . (str_contains($out, '== ') ? '' : ' (keine Änderungen)');
            $errors = $this->runSelfTest();
            if ($errors !== []) {
                throw new UpdateException('Selbsttest fehlgeschlagen: ' . implode('; ', $errors));
            }
            $log[] = 'Selbsttest bestanden.';
            $this->finish($id, 'installed', $log);
            return ['id' => $id, 'status' => 'installed', 'log' => $log];
        } catch (\Throwable $e) {
            $log[] = 'Fehler: ' . $e->getMessage();
            $this->log->error('Update fehlgeschlagen', ['version' => $info['version'], 'error' => $e->getMessage()]);
            $this->rollback($rollbackDir, $backupId, $log);
            $this->finish($id, 'rolled_back', $log);
            throw new UpdateException('Das Update ist fehlgeschlagen und wurde zurückgenommen: ' . $e->getMessage(), 0, $e);
        } finally {
            @unlink($flag);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Letztes installiertes Update zurücknehmen (Programmdateien und Datenbankstand vor dem Update). @return list<string> */
    public function revertLast(?int $userId): array
    {
        self::keepRunning();
        $u = $this->db->one("SELECT * FROM app_updates WHERE status = 'installed' ORDER BY id DESC LIMIT 1");
        if ($u === null) {
            throw new UpdateException('Es gibt kein installiertes Update, das zurückgenommen werden kann.');
        }
        $dir = $this->root . '/storage/updates/rollback-' . $u['id'];
        if (!is_file($dir . '/rollback.json')) {
            throw new UpdateException('Für dieses Update liegen keine Rücknahmedaten vor.');
        }
        $lock = $this->backups->lock();
        $flag = App::maintenanceFlag($this->root);
        $log = [];
        try {
            $this->backups->create('pre_restore', $userId, 'Vor Rücknahme von Update ' . $u['version']);
            file_put_contents($flag, 'update');
            $this->rollback($dir, $u['backup_id'] !== null ? (int) $u['backup_id'] : null, $log);
            $this->db->run("UPDATE app_updates SET status = 'rolled_back', log = CONCAT(COALESCE(log, ''), ?) WHERE id = ?", ["\nZurückgenommen: " . implode(' ', $log), $u['id']]);
            return $log;
        } finally {
            @unlink($flag);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{0:array<string,mixed>, 1:\ZipArchive} */
    private function open(string $package): array
    {
        $pk = $this->publicKeyB64 ? base64_decode($this->publicKeyB64, true) : false;
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new UpdateException('Kein Prüfschlüssel für Updates hinterlegt (RELEASE_PUBLIC_KEY in der .env).');
        }
        $zip = new \ZipArchive();
        if ($zip->open($package) !== true) {
            throw new UpdateException('Die Datei ist kein gültiges Updatepaket.');
        }
        $json = $zip->getFromName('update.json');
        $sig = base64_decode(trim((string) $zip->getFromName('update.sig')), true);
        if ($json === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($sig, $json, $pk)) {
            throw new UpdateException('Die Signatur des Pakets ist ungültig. Das Paket stammt nicht vom Entwickler oder wurde verändert.');
        }
        $meta = json_decode($json, true);
        if (!is_array($meta) || ($meta['format'] ?? null) !== 1 || !is_string($meta['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/', $meta['version']) || !is_array($meta['files'] ?? null)) {
            throw new UpdateException('Die Paketbeschreibung ist unvollständig.');
        }
        foreach ($meta['files'] as $path => $hash) {
            $path = (string) $path;
            if (str_contains($path, '..') || str_contains($path, "\0") || !preg_match(self::TARGETS, $path)) {
                throw new UpdateException("Unzulässiger Pfad im Paket: $path");
            }
            $content = $zip->getStream('files/' . $path);
            if ($content === false) {
                throw new UpdateException("Datei fehlt im Paket: $path");
            }
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $content);
            fclose($content);
            if (!hash_equals((string) $hash, hash_final($ctx))) {
                throw new UpdateException("Prüfsumme stimmt nicht: $path");
            }
        }
        return [$meta, $zip];
    }

    /** @param array<string,mixed> $meta @param list<string> $log */
    private function installFiles(\ZipArchive $zip, array $meta, string $rollbackDir, array &$log): void
    {
        $guard = new FileGuard($this->root, null);
        $current = array_filter(array_keys($guard->scan()), fn($p) => preg_match(self::TARGETS, $p) === 1);
        $incoming = array_keys($meta['files']);
        $obsolete = array_values(array_diff($current, $incoming));
        @mkdir($rollbackDir . '/files', 0700, true);
        $added = [];
        // 1. Alten Stand sichern (geänderte und zu löschende Dateien)
        foreach (array_merge($incoming, $obsolete) as $rel) {
            $abs = $this->root . '/' . $rel;
            if (is_file($abs)) {
                @mkdir(dirname($rollbackDir . '/files/' . $rel), 0700, true);
                copy($abs, $rollbackDir . '/files/' . $rel);
            } else {
                $added[] = $rel;
            }
        }
        file_put_contents($rollbackDir . '/rollback.json', json_encode(['added' => $added, 'saved' => array_values(array_diff(array_merge($incoming, $obsolete), $added))]));
        // 2. Neue Dateien schreiben (je Datei atomar über Umbenennen)
        foreach ($incoming as $rel) {
            $abs = $this->root . '/' . $rel;
            @mkdir(dirname($abs), 0755, true);
            $tmp = $abs . '.upd-' . bin2hex(random_bytes(3));
            if (file_put_contents($tmp, (string) $zip->getFromName('files/' . $rel)) === false || !rename($tmp, $abs)) {
                @unlink($tmp);
                throw new UpdateException("Datei kann nicht geschrieben werden: $rel");
            }
        }
        // 3. Veraltete Programmdateien entfernen
        foreach ($obsolete as $rel) {
            @unlink($this->root . '/' . $rel);
        }
        $log[] = count($incoming) . ' Dateien installiert, ' . count($obsolete) . ' veraltete entfernt.';
    }

    /** @param list<string> $log */
    private function rollback(string $rollbackDir, ?int $backupId, array &$log): void
    {
        if (is_file($rollbackDir . '/rollback.json')) {
            $r = json_decode((string) file_get_contents($rollbackDir . '/rollback.json'), true);
            foreach ((array) ($r['added'] ?? []) as $rel) {
                @unlink($this->root . '/' . $rel);
            }
            foreach ((array) ($r['saved'] ?? []) as $rel) {
                @mkdir(dirname($this->root . '/' . $rel), 0755, true);
                copy($rollbackDir . '/files/' . $rel, $this->root . '/' . $rel);
            }
            $log[] = 'Programmdateien zurückgesetzt.';
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        }
        if ($backupId !== null) {
            try {
                $this->backups->restore($backupId, null, false, true);
                $log[] = "Datenbank und Dateien aus Sicherung #$backupId wiederhergestellt.";
            } catch (\Throwable $e) {
                $log[] = 'ACHTUNG: Datenbank konnte nicht automatisch zurückgesetzt werden: ' . $e->getMessage() . ' Bitte Notfallanleitung (docs/betrieb.md) befolgen.';
                $this->log->critical('Rückfall nach Update fehlgeschlagen', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Update nicht abbrechen, wenn der Browser oder der vorgeschaltete Webserver aufhört zu warten
     * (z. B. 504 nach 60 Sekunden). Ein halb eingespieltes Update wäre schlimmer als ein langsames.
     */
    private static function keepRunning(): void
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
    }

    /** Kommandozeilen-PHP finden; im Webserver ist PHP_BINARY oft php-cgi oder php-fpm. */
    private static function cliPhp(): string
    {
        $bin = (string) PHP_BINARY;
        if ($bin !== '' && preg_match('/(fpm|cgi)/', basename($bin)) !== 1) {
            return $bin;
        }
        foreach ([PHP_BINDIR . '/php', dirname($bin) . '/php'] as $c) {
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }
        return 'php';
    }

    /** @return list<string> Fehler */
    private function runSelfTest(): array
    {
        if ($this->selfTest !== null) {
            return ($this->selfTest)();
        }
        $errors = [];
        foreach (['settings', 'exam_types', 'exam_sessions', 'bookings', 'users'] as $t) {
            try {
                $this->db->value("SELECT COUNT(*) FROM `$t`");
            } catch (\Throwable $e) {
                $errors[] = "Tabelle $t nicht lesbar";
            }
        }
        // Eigenständiger PHP-Prozess lädt den neuen Programmstand (feste Befehlszeile, keine Benutzereingaben)
        $php = self::cliPhp();
        $cmd = [$php, $this->root . '/bin/console', 'selftest'];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (is_resource($proc)) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            if (proc_close($proc) !== 0) {
                $errors[] = 'Selbsttest: ' . mb_substr(trim((string) $out), 0, 300);
            }
        }
        return $errors;
    }

    /** @param list<string> $log */
    private function finish(int $id, string $status, array $log): void
    {
        $this->db->run('UPDATE app_updates SET status = ?, log = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?', [$status, implode("\n", $log), $id]);
    }

    /**
     * Paket bauen und signieren (nur auf dem Entwicklungsrechner, mit dem geheimen Schlüssel).
     * @param string $sourceRoot Build-Ordner mit Produktionsabhängigkeiten (composer install --no-dev)
     */
    public static function build(string $sourceRoot, string $version, string $secretKeyB64, string $changelog, string $minVersion, string $out): void
    {
        $sk = base64_decode($secretKeyB64, true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Ungültiger geheimer Schlüssel.');
        }
        file_put_contents($sourceRoot . '/VERSION', $version . "\n");
        $guard = new FileGuard($sourceRoot, null);
        $guard->writeSignedManifest($secretKeyB64, $version);
        $files = [];
        foreach (array_keys($guard->scan()) as $rel) {
            if (preg_match(self::TARGETS, $rel)) {
                $files[$rel] = hash_file('sha256', $sourceRoot . '/' . $rel);
            }
        }
        foreach (['integrity.json', 'integrity.sig'] as $rel) {
            $files[$rel] = hash_file('sha256', $sourceRoot . '/' . $rel);
        }
        $meta = json_encode(['format' => 1, 'version' => $version, 'created_at' => gmdate('c'), 'changelog' => $changelog,
            'requires' => ['min_version' => $minVersion, 'php' => '8.3.0', 'extensions' => ['pdo_mysql', 'sodium', 'zip', 'gd', 'intl', 'mbstring']],
            'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $zip = new \ZipArchive();
        $zip->open($out, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('update.json', (string) $meta);
        $zip->addFromString('update.sig', base64_encode(sodium_crypto_sign_detached((string) $meta, $sk)));
        foreach (array_keys($files) as $rel) {
            $zip->addFile($sourceRoot . '/' . $rel, 'files/' . $rel);
        }
        $zip->close();
    }
}
