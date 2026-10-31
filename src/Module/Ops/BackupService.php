<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Core\Database;
use Psr\Log\LoggerInterface;

/**
 * Sicherung und Wiederherstellung: Datenbank (als SQL), hochgeladene Originale und Medien.
 * Dateien liegen verschlüsselt außerhalb des Webroots (storage/backups). Die Zugangsdaten
 * (.env) sind bewusst nicht enthalten und müssen getrennt aufbewahrt werden.
 */
final class BackupService
{
    /** Tabellen, die nicht gesichert und bei der Wiederherstellung nicht überschrieben werden */
    public const SKIP_TABLES = ['backups', 'rate_limits', 'cron_runs'];
    /** Gesicherte Ordner relativ zum Projekt */
    public const DIRS = ['storage/uploads', 'public/media'];
    private const SEP = "\n-- @@MEA@@\n";

    public function __construct(
        private Database $db,
        private string $root,
        private BackupCrypto $crypto,
        private LoggerInterface $log,
        private string $appVersion,
    ) {}

    public function dir(): string
    {
        $d = $this->root . '/storage/backups';
        if (!is_dir($d)) {
            mkdir($d, 0700, true);
        }
        return $d;
    }

    /** @return int ID der Sicherung */
    public function create(string $kind, ?int $userId = null, string $note = ''): int
    {
        $free = @disk_free_space($this->root);
        $file = sprintf('mea-%s-%s-%s.meabak', gmdate('Ymd-His'), $kind, bin2hex(random_bytes(3)));
        $id = $this->db->insert('backups', ['file_name' => $file, 'kind' => $kind, 'status' => 'running', 'note' => $note ?: null,
            'created_by' => $userId, 'app_version' => $this->appVersion]);
        $tmp = $this->tmpDir();
        try {
            if ($free !== false && $free < 200 * 1024 * 1024) {
                throw new \RuntimeException('Zu wenig freier Speicherplatz für eine Sicherung.');
            }
            [$sqlFile, $counts] = $this->dumpDatabase($tmp);
            $zipFile = $tmp . '/backup.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Archiv kann nicht angelegt werden.');
            }
            $files = ['database.sql' => hash_file('sha256', $sqlFile)];
            $zip->addFile($sqlFile, 'database.sql');
            foreach (self::DIRS as $dir) {
                foreach ($this->filesIn($this->root . '/' . $dir) as $abs) {
                    $rel = 'files/' . $dir . '/' . substr($abs, strlen($this->root . '/' . $dir) + 1);
                    $zip->addFile($abs, $rel);
                    $files[$rel] = hash_file('sha256', $abs);
                }
            }
            $schema = (string) $this->db->value('SELECT MAX(version) FROM schema_migrations');
            $manifest = ['format' => 1, 'app_version' => $this->appVersion, 'schema_version' => $schema, 'created_at' => gmdate('c'),
                'kind' => $kind, 'tables' => $counts, 'files' => $files];
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new \RuntimeException('Archiv konnte nicht geschrieben werden.');
            }
            $target = $this->dir() . '/' . $file;
            $this->crypto->encryptFile($zipFile, $target);
            chmod($target, 0600);
            $this->db->run("UPDATE backups SET status = 'ok', size_bytes = ?, sha256 = ?, schema_version = ?, finished_at = UTC_TIMESTAMP(), verified_at = UTC_TIMESTAMP() WHERE id = ?",
                [filesize($target), hash_file('sha256', $target), $schema, $id]);
            return $id;
        } catch (\Throwable $e) {
            $this->log->error('Sicherung fehlgeschlagen', ['error' => $e->getMessage()]);
            $this->db->run("UPDATE backups SET status = 'failed', error = ?, finished_at = UTC_TIMESTAMP() WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $id]);
            @unlink($this->dir() . '/' . $file);
            throw new BackupException($e->getMessage(), 0, $e);
        } finally {
            $this->removeDir($tmp);
        }
    }

    /**
     * Prüft Schlüssel, Unversehrtheit und Inhaltsverzeichnis einer Sicherungsdatei.
     * @return array<string,mixed> Manifest
     */
    public function verifyFile(string $path, ?string $extractTo = null): array
    {
        $tmp = $this->tmpDir();
        try {
            $zipFile = $tmp . '/backup.zip';
            $this->crypto->decryptFile($path, $zipFile);
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                throw new BackupException('Das Archiv in der Sicherung ist beschädigt.');
            }
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 1 || !is_array($manifest['files'] ?? null)) {
                throw new BackupException('Die Sicherung hat kein gültiges Inhaltsverzeichnis.');
            }
            foreach ($manifest['files'] as $name => $hash) {
                $name = (string) $name;
                if (str_contains($name, '..') || str_starts_with($name, '/') || !preg_match('#^(database\.sql|files/(storage/uploads|public/media)/[^\x00]+)$#', $name)) {
                    throw new BackupException('Die Sicherung enthält einen unzulässigen Pfad.');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new BackupException("Datei fehlt in der Sicherung: $name");
                }
                $ctx = hash_init('sha256');
                hash_update_stream($ctx, $stream);
                fclose($stream);
                if (!hash_equals((string) $hash, hash_final($ctx))) {
                    throw new BackupException("Prüfsumme stimmt nicht: $name");
                }
            }
            if ($zip->numFiles !== count($manifest['files']) + 1) {
                throw new BackupException('Die Sicherung enthält nicht aufgeführte Dateien.');
            }
            if ($extractTo !== null) {
                $zip->extractTo($extractTo);
            }
            $zip->close();
            return $manifest;
        } catch (\RuntimeException $e) {
            throw $e instanceof BackupException ? $e : new BackupException($e->getMessage(), 0, $e);
        } finally {
            $this->removeDir($tmp);
        }
    }

    /** @return array<string,mixed> */
    public function verify(int $id): array
    {
        $b = $this->get($id);
        $path = $this->dir() . '/' . $b['file_name'];
        if (!is_file($path) || !hash_equals((string) $b['sha256'], (string) hash_file('sha256', $path))) {
            throw new BackupException('Die Sicherungsdatei fehlt oder wurde verändert.');
        }
        $m = $this->verifyFile($path);
        $this->db->run('UPDATE backups SET verified_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
        return $m;
    }

    /** Mindestlänge für das Passwort einer ZIP-Ausgabe. */
    public const ZIP_PASSWORD_MIN = 12;

    /**
     * Sicherung als ZIP mit eigenem Passwort (AES-256 je Datei) für die Ablage außerhalb des Servers.
     * Lässt sich mit 7-Zip, WinZip oder macOS-Programmen wie Keka öffnen und hier wieder hochladen.
     * Gibt den Pfad einer temporären Datei zurück; der Aufrufer löscht sie nach dem Senden (removeExport()).
     */
    public function exportZip(int $id, string $password): string
    {
        if (mb_strlen($password) < self::ZIP_PASSWORD_MIN) {
            throw new BackupException('Das Passwort muss mindestens ' . self::ZIP_PASSWORD_MIN . ' Zeichen lang sein.');
        }
        $b = $this->get($id);
        $this->verifyFile($this->path($b));
        $tmp = $this->tmpDir();
        $zipFile = $tmp . '/' . preg_replace('/\.meabak$/', '.zip', basename((string) $b['file_name']));
        $this->crypto->decryptFile($this->path($b), $zipFile);
        try {
            $this->setZipEncryption($zipFile, \ZipArchive::EM_AES_256, $password);
        } catch (\Throwable $e) {
            $this->removeDir($tmp);
            throw $e instanceof BackupException ? $e : new BackupException($e->getMessage(), 0, $e);
        }
        chmod($zipFile, 0600);
        return $zipFile;
    }

    /** Temporären Ordner einer ZIP-Ausgabe entfernen. */
    public function removeExport(string $zipFile): void
    {
        $dir = dirname($zipFile);
        if (str_starts_with(basename($dir), 'op-') && dirname($dir) === $this->root . '/storage/tmp') {
            $this->removeDir($dir);
        }
    }

    /** Verschlüsselung aller Einträge setzen oder (mit Passwort zum Lesen) entfernen. */
    private function setZipEncryption(string $zipFile, int $method, string $password): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new BackupException('Das Archiv ist beschädigt.');
        }
        if ($method === \ZipArchive::EM_NONE) {
            $zip->setPassword($password);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!$zip->setEncryptionIndex($i, $method, $method === \ZipArchive::EM_NONE ? null : $password)) {
                $zip->close();
                throw new BackupException('Verschlüsselung konnte nicht gesetzt werden.');
            }
        }
        if (!@$zip->close()) {
            throw new BackupException($method === \ZipArchive::EM_NONE ? 'Das Passwort ist falsch oder die ZIP-Datei ist beschädigt.' : 'Archiv konnte nicht geschrieben werden.');
        }
    }

    /** Mit Passwort geschützte ZIP-Ausgabe in eine normale Sicherung (.meabak) umwandeln. */
    private function zipToMeabak(string $uploadedFile, string $password, string $tmp): string
    {
        $plain = $tmp . '/upload.zip';
        if (!copy($uploadedFile, $plain)) {
            throw new BackupException('Datei konnte nicht gelesen werden.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($plain) !== true) {
            throw new BackupException('Die ZIP-Datei ist beschädigt.');
        }
        $zip->setPassword($password);
        $probe = $zip->getFromName('manifest.json');
        $zip->close();
        if ($probe === false) {
            throw new BackupException('Das Passwort ist falsch oder die ZIP-Datei ist keine Sicherung dieser Website.');
        }
        $this->setZipEncryption($plain, \ZipArchive::EM_NONE, $password);
        $out = $tmp . '/upload.meabak';
        $this->crypto->encryptFile($plain, $out);
        return $out;
    }

    /** Hochgeladene Sicherung übernehmen: .meabak (mit dem eigenen Schlüssel prüfbar) oder ZIP-Ausgabe mit Passwort. */
    public function import(string $uploadedFile, ?int $userId, string $zipPassword = ''): int
    {
        $tmp = null;
        if (file_get_contents($uploadedFile, false, null, 0, 4) === "PK\x03\x04") {
            if ($zipPassword === '') {
                throw new BackupException('Für eine ZIP-Datei bitte das Passwort eingeben, das beim Herunterladen vergeben wurde.');
            }
            $tmp = $this->tmpDir();
            try {
                $uploadedFile = $this->zipToMeabak($uploadedFile, $zipPassword, $tmp);
            } catch (\Throwable $e) {
                $this->removeDir($tmp);
                throw $e instanceof BackupException ? $e : new BackupException($e->getMessage(), 0, $e);
            }
        }
        try {
            return $this->importMeabak($uploadedFile, $userId);
        } finally {
            if ($tmp !== null) {
                $this->removeDir($tmp);
            }
        }
    }

    private function importMeabak(string $uploadedFile, ?int $userId): int
    {
        $m = $this->verifyFile($uploadedFile);
        $file = sprintf('mea-%s-uploaded-%s.meabak', gmdate('Ymd-His'), bin2hex(random_bytes(3)));
        $target = $this->dir() . '/' . $file;
        if (!copy($uploadedFile, $target)) {
            throw new BackupException('Datei konnte nicht gespeichert werden.');
        }
        chmod($target, 0600);
        return $this->db->insert('backups', [
            'file_name' => $file, 'kind' => 'uploaded', 'status' => 'ok', 'size_bytes' => filesize($target), 'sha256' => hash_file('sha256', $target),
            'app_version' => (string) ($m['app_version'] ?? ''), 'schema_version' => (string) ($m['schema_version'] ?? ''),
            'note' => 'Hochgeladen, erstellt am ' . (string) ($m['created_at'] ?? '?'), 'created_by' => $userId,
            'finished_at' => gmdate('Y-m-d H:i:s'), 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Wiederherstellung. Vorher wird der aktuelle Stand gesichert. Schlägt etwas fehl,
     * wird automatisch dieser Stand zurückgespielt.
     * @return array{pre_backup:int, log:list<string>}
     */
    public function restore(int $id, ?int $userId, bool $withSafetyBackup = true, bool $alreadyLocked = false): array
    {
        $lock = $alreadyLocked ? null : $this->lock();
        $log = [];
        $flag = \Mea\Core\App::maintenanceFlag($this->root);
        $hadFlag = is_file($flag);
        $pre = 0;
        try {
            $b = $this->get($id);
            $path = $this->dir() . '/' . $b['file_name'];
            $this->verifyFile($path);
            $log[] = 'Sicherung geprüft.';
            if ($withSafetyBackup) {
                $pre = $this->create('pre_restore', $userId, 'Vor Wiederherstellung von #' . $id);
                $log[] = "Aktueller Stand gesichert (#$pre).";
            }
            touch($flag);
            try {
                $this->apply($path, $log);
            } catch (\Throwable $e) {
                $log[] = 'Fehler: ' . $e->getMessage();
                $this->log->error('Wiederherstellung fehlgeschlagen', ['backup' => $id, 'error' => $e->getMessage()]);
                if ($pre > 0) {
                    $log[] = 'Spiele den vorherigen Stand zurück …';
                    $this->apply($this->dir() . '/' . $this->get($pre)['file_name'], $log);
                    $log[] = 'Vorheriger Stand wiederhergestellt.';
                }
                throw new BackupException('Die Wiederherstellung ist fehlgeschlagen. ' . ($pre > 0 ? 'Der vorherige Stand wurde wiederhergestellt.' : ''), 0, $e);
            }
            return ['pre_backup' => $pre, 'log' => $log];
        } finally {
            if (!$hadFlag) {
                @unlink($flag);
            }
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param list<string> $log */
    private function apply(string $path, array &$log): void
    {
        $tmp = $this->tmpDir();
        try {
            $manifest = $this->verifyFile($path, $tmp);
            $this->importSql($tmp . '/database.sql');
            $log[] = 'Datenbank eingespielt.';
            $migrator = new Migrator($this->db->pdo(), $this->root . '/migrations');
            if ($migrator->pending() > 0) {
                $migrator->migrate();
                $log[] = 'Datenbank auf den aktuellen Programmstand gebracht.';
            }
            foreach (self::DIRS as $dir) {
                $this->swapDir($tmp . '/files/' . $dir, $this->root . '/' . $dir);
            }
            $log[] = 'Dateien eingespielt.';
            $this->selfTest($manifest);
            $log[] = 'Prüfung nach der Wiederherstellung erfolgreich.';
        } finally {
            $this->removeDir($tmp);
        }
    }

    /** Konsistenz prüfen: Tabellen und Zeilenzahlen wie in der Sicherung, Kernabfragen laufen. @param array<string,mixed> $manifest */
    private function selfTest(array $manifest): void
    {
        foreach ((array) ($manifest['tables'] ?? []) as $table => $count) {
            $actual = (int) $this->db->value('SELECT COUNT(*) FROM `' . str_replace('`', '', (string) $table) . '`');
            if ($actual !== (int) $count) {
                throw new BackupException("Tabelle $table: $actual statt $count Zeilen.");
            }
        }
        foreach (['settings', 'exam_types', 'users', 'bookings'] as $t) {
            $this->db->value("SELECT COUNT(*) FROM `$t`");
        }
    }

    /** @return array{0:string, 1:array<string,int>} */
    private function dumpDatabase(string $tmp): array
    {
        $pdo = $this->db->pdo();
        $file = $tmp . '/database.sql';
        $fh = fopen($file, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Temporäre Datei nicht beschreibbar.');
        }
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }
        $counts = [];
        try {
            fwrite($fh, '-- MEA Sicherung ' . gmdate('c') . self::SEP);
            foreach ($this->tables() as $t) {
                $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1];
                fwrite($fh, "DROP TABLE IF EXISTS `$t`;" . self::SEP . $create . ';' . self::SEP);
                $stmt = $pdo->query("SELECT * FROM `$t`");
                $batch = [];
                $n = 0;
                while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                    $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                    $n++;
                    if (count($batch) >= 200) {
                        fwrite($fh, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ';' . self::SEP);
                        $batch = [];
                    }
                }
                if ($batch) {
                    fwrite($fh, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ';' . self::SEP);
                }
                $counts[$t] = $n;
            }
        } finally {
            if ($own) {
                $pdo->exec('COMMIT');
            }
            fclose($fh);
        }
        return [$file, $counts];
    }

    private function importSql(string $file): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // Tabellen, die es in der Sicherung nicht gibt (neuere Version), vorher entfernen
            $inDump = [];
            $buffer = '';
            $fh = fopen($file, 'rb');
            while (($line = fgets($fh)) !== false) {
                $buffer .= $line;
                if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
                    $inDump[] = $m[1];
                }
            }
            fclose($fh);
            foreach ($this->tables() as $t) {
                if (!in_array($t, $inDump, true)) {
                    $pdo->exec("DROP TABLE `$t`");
                }
            }
            foreach (explode(self::SEP, $buffer) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '' || str_starts_with($stmt, '--')) {
                    continue;
                }
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $stmt, $m) && in_array($m[2], self::SKIP_TABLES, true)) {
                    continue;
                }
                $pdo->exec($stmt);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $out = [];
        foreach ($this->db->pdo()->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $r) {
            if ($r[1] === 'BASE TABLE' && !in_array($r[0], self::SKIP_TABLES, true)) {
                $out[] = (string) $r[0];
            }
        }
        return $out;
    }

    /** Verzeichnis austauschen; bei Fehler bleibt das alte erhalten. */
    private function swapDir(string $new, string $target): void
    {
        if (!is_dir($new)) {
            mkdir($new, 0755, true); // Sicherung ohne Dateien in diesem Ordner
        }
        $old = $target . '.old-' . bin2hex(random_bytes(4));
        if (is_dir($target) && !rename($target, $old)) {
            throw new BackupException("Ordner kann nicht ersetzt werden: $target");
        }
        if (!rename($new, $target)) {
            if (is_dir($old)) {
                rename($old, $target);
            }
            throw new BackupException("Ordner kann nicht eingespielt werden: $target");
        }
        $this->removeDir($old);
    }

    /** Alte Sicherungen nach Fristen löschen; die drei neuesten erfolgreichen bleiben immer. */
    public function prune(int $keepDays, int $keepManualDays): int
    {
        $keep = array_column($this->db->all("SELECT id FROM backups WHERE status = 'ok' ORDER BY id DESC LIMIT 3"), 'id');
        $n = 0;
        foreach ($this->db->all('SELECT * FROM backups') as $b) {
            if (in_array($b['id'], $keep, false)) {
                continue;
            }
            $days = in_array($b['kind'], ['manual', 'uploaded'], true) ? $keepManualDays : $keepDays;
            $age = (time() - (int) strtotime($b['created_at'] . ' UTC')) / 86400;
            if ($b['status'] === 'failed' && $age > 7 || $b['status'] !== 'running' && $age > $days) {
                @unlink($this->dir() . '/' . basename((string) $b['file_name']));
                $this->db->run('DELETE FROM backups WHERE id = ?', [$b['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $b = $this->db->one('SELECT * FROM backups WHERE id = ?', [$id]);
        if ($b === null || $b['status'] !== 'ok') {
            throw new BackupException('Diese Sicherung gibt es nicht oder sie ist unvollständig.');
        }
        return $b;
    }

    public function path(array $b): string
    {
        return $this->dir() . '/' . basename((string) $b['file_name']);
    }

    /** @return resource Sperre gegen parallele Wiederherstellungen und Updates */
    public function lock()
    {
        $fh = fopen($this->root . '/storage/ops.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            throw new BackupException('Es läuft bereits eine Wiederherstellung oder ein Update. Bitte warten.');
        }
        return $fh;
    }

    /** @return list<string> */
    private function filesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile() && !$f->isLink()) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    private function tmpDir(): string
    {
        $d = $this->root . '/storage/tmp/op-' . bin2hex(random_bytes(6));
        mkdir($d, 0700, true);
        return $d;
    }

    public function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
