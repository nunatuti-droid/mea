<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Module\Ops\BackupCrypto;
use Mea\Module\Ops\BackupException;
use Mea\Module\Ops\BackupService;
use Mea\Module\Ops\UpdateException;
use Mea\Module\Ops\UpdateJobs;
use Mea\Module\Ops\UpdateService;
use Mea\Module\Security\FileGuard;
use Psr\Log\NullLogger;

/**
 * Sicherung, Wiederherstellung (mit automatischem Rückfall) und Updates in einem
 * temporären Projektordner gegen die Testdatenbank. Läuft ohne Transaktion, weil
 * Tabellen neu angelegt werden; die Testdatenbank wird danach neu aufgebaut.
 */
final class OpsTest extends IntegrationTestCase
{
    protected bool $transactional = false;
    private string $tmp;
    private BackupService $backups;
    private BackupCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        // Fester Pfad je Prozess: Phinx lädt Migrationsklassen nur einmal pro Datei
        $this->tmp = sys_get_temp_dir() . '/mea-ops-' . getmypid();
        foreach (['storage/uploads/originals', 'public/media/2026', 'storage/backups', 'storage/tmp', 'src', 'templates', 'migrations'] as $d) {
            mkdir($this->tmp . '/' . $d, 0755, true);
        }
        foreach (glob(self::root() . '/migrations/*.php') as $f) {
            copy($f, $this->tmp . '/migrations/' . basename($f));
        }
        file_put_contents($this->tmp . '/storage/uploads/originals/a.jpg', 'ORIGINAL-A');
        file_put_contents($this->tmp . '/public/media/2026/a-800.webp', 'WEBP-A');
        file_put_contents($this->tmp . '/VERSION', "1.0.0\n");
        file_put_contents($this->tmp . '/src/Foo.php', "<?php // v1\n");
        file_put_contents($this->tmp . '/src/Old.php', "<?php // alt\n");
        file_put_contents($this->tmp . '/templates/page.twig', "v1\n");
        $this->crypto = new BackupCrypto(random_bytes(32));
        $this->backups = new BackupService(self::db(), $this->tmp, $this->crypto, new NullLogger(), '1.0.0');
    }

    protected function tearDown(): void
    {
        $this->backups->removeDir($this->tmp);
        parent::tearDown();
    }

    private function marker(): ?string
    {
        return self::db()->value("SELECT value_json FROM settings WHERE setting_key = 'ops_test_marker'");
    }

    private function setMarker(string $v): void
    {
        self::db()->run("INSERT INTO settings (setting_key, value_json) VALUES ('ops_test_marker', ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)", [json_encode($v)]);
    }

    public function testBackupAndRestoreRoundTrip(): void
    {
        $this->setMarker('vorher');
        $id = $this->backups->create('manual', null, 'Test');
        $b = $this->backups->get($id);
        self::assertSame('ok', $b['status']);
        $path = $this->backups->path($b);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        self::assertStringStartsWith("MEABAK1\n", (string) file_get_contents($path, false, null, 0, 8));
        self::assertStringNotContainsString('ops_test_marker', (string) file_get_contents($path), 'Inhalt muss verschlüsselt sein');
        $manifest = $this->backups->verify($id);
        self::assertArrayHasKey('files/public/media/2026/a-800.webp', $manifest['files']);
        self::assertArrayHasKey('exam_types', $manifest['tables']);

        // Änderungen nach der Sicherung
        $this->setMarker('nachher');
        $examsBefore = (int) self::db()->value('SELECT COUNT(*) FROM exam_types');
        $this->createExam('nach-sicherung');
        file_put_contents($this->tmp . '/public/media/2026/a-800.webp', 'VERÄNDERT');
        file_put_contents($this->tmp . '/public/media/2026/neu.webp', 'NEU');

        $r = $this->backups->restore($id, null);
        self::assertGreaterThan(0, $r['pre_backup']);
        self::assertSame(json_encode('vorher'), $this->marker());
        self::assertSame($examsBefore, (int) self::db()->value('SELECT COUNT(*) FROM exam_types'));
        self::assertSame('WEBP-A', file_get_contents($this->tmp . '/public/media/2026/a-800.webp'));
        self::assertFileDoesNotExist($this->tmp . '/public/media/2026/neu.webp');
        self::assertSame('ORIGINAL-A', file_get_contents($this->tmp . '/storage/uploads/originals/a.jpg'));
        self::assertFileDoesNotExist(\Mea\Core\App::maintenanceFlag($this->tmp));
        // Die Sicherungsliste selbst wird nicht überschrieben
        self::assertSame('pre_restore', self::db()->value('SELECT kind FROM backups WHERE id = ?', [$r['pre_backup']]));
    }

    public function testTamperedBackupIsRejected(): void
    {
        $id = $this->backups->create('manual');
        $path = $this->backups->path($this->backups->get($id));
        $data = (string) file_get_contents($path);
        $data[200] = chr(ord($data[200]) ^ 1);
        file_put_contents($path, $data);
        $this->expectException(BackupException::class);
        $this->backups->verify($id);
    }

    public function testFailedRestoreRollsBackToPreviousState(): void
    {
        $this->setMarker('aktuell');
        $exams = (int) self::db()->value('SELECT COUNT(*) FROM exam_types');
        // Gültig verschlüsselte, aber fehlerhafte Sicherung: löscht erst Tabellen, dann bricht das SQL ab
        $sql = "-- MEA Sicherung\n-- @@MEA@@\nDROP TABLE IF EXISTS `settings`;\n-- @@MEA@@\nTHIS IS NOT SQL;\n-- @@MEA@@\n";
        $zipFile = $this->tmp . '/storage/tmp/bad.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('database.sql', $sql);
        $zip->addFromString('manifest.json', (string) json_encode(['format' => 1, 'tables' => [], 'files' => ['database.sql' => hash('sha256', $sql)]]));
        $zip->close();
        $this->crypto->encryptFile($zipFile, $this->backups->dir() . '/bad.meabak');
        $id = self::db()->insert('backups', ['file_name' => 'bad.meabak', 'kind' => 'uploaded', 'status' => 'ok']);

        try {
            $this->backups->restore($id, null);
            self::fail('Wiederherstellung hätte fehlschlagen müssen');
        } catch (BackupException $e) {
            self::assertStringContainsString('vorherige Stand wurde wiederhergestellt', $e->getMessage());
        }
        self::assertSame(json_encode('aktuell'), $this->marker());
        self::assertSame($exams, (int) self::db()->value('SELECT COUNT(*) FROM exam_types'));
        self::assertSame('WEBP-A', file_get_contents($this->tmp . '/public/media/2026/a-800.webp'));
        self::assertFileDoesNotExist(\Mea\Core\App::maintenanceFlag($this->tmp));
    }

    /** @return array{0:string,1:string} Paketpfad, öffentlicher Schlüssel */
    private function buildPackage(string $version, ?array $keys = null, string $min = '1.0.0'): array
    {
        $keys ??= FileGuard::generateKeyPair();
        $src = $this->tmp . '/storage/tmp/build-' . $version;
        foreach (['src', 'templates', 'migrations'] as $d) {
            mkdir($src . '/' . $d, 0755, true);
        }
        foreach (glob($this->tmp . '/migrations/*.php') as $f) {
            copy($f, $src . '/migrations/' . basename($f));
        }
        file_put_contents($src . '/src/Foo.php', "<?php // v2\n");
        file_put_contents($src . '/src/Bar.php', "<?php // neu\n");
        file_put_contents($src . '/templates/page.twig', "v2\n");
        $out = $this->tmp . '/storage/tmp/update-' . $version . '.zip';
        UpdateService::build($src, $version, $keys['secret'], 'Testupdate', $min, $out);
        return [$out, $keys['public']];
    }

    private function updates(string $publicKey, ?\Closure $selfTest = null): UpdateService
    {
        $u = new UpdateService(self::db(), $this->tmp, $publicKey, $this->backups, new NullLogger(), '1.0.0');
        $u->useSelfTest($selfTest ?? fn() => []);
        return $u;
    }

    public function testUpdateInstallAndRevert(): void
    {
        [$pkg, $pk] = $this->buildPackage('1.1.0');
        $u = $this->updates($pk);
        $info = $u->inspect($pkg);
        self::assertSame('1.1.0', $info['version']);
        self::assertSame([], $info['problems']);

        $r = $u->install($pkg, null);
        self::assertSame('installed', $r['status']);
        self::assertSame("<?php // v2\n", file_get_contents($this->tmp . '/src/Foo.php'));
        self::assertFileExists($this->tmp . '/src/Bar.php');
        self::assertFileDoesNotExist($this->tmp . '/src/Old.php');
        self::assertSame('1.1.0', trim((string) file_get_contents($this->tmp . '/VERSION')));
        self::assertFileExists($this->tmp . '/integrity.sig');
        self::assertFileDoesNotExist(\Mea\Core\App::maintenanceFlag($this->tmp));
        // Daten und Uploads bleiben unberührt
        self::assertSame('ORIGINAL-A', file_get_contents($this->tmp . '/storage/uploads/originals/a.jpg'));
        self::assertNotNull(self::db()->value('SELECT backup_id FROM app_updates WHERE id = ?', [$r['id']]));
        // Gleiches Paket nicht doppelt
        self::assertNotSame([], $this->updates($pk)->inspect($pkg)['problems']);

        $u->revertLast(null);
        self::assertSame("<?php // v1\n", file_get_contents($this->tmp . '/src/Foo.php'));
        self::assertFileDoesNotExist($this->tmp . '/src/Bar.php');
        self::assertFileExists($this->tmp . '/src/Old.php');
        self::assertSame('1.0.0', trim((string) file_get_contents($this->tmp . '/VERSION')));
        self::assertSame('rolled_back', self::db()->value('SELECT status FROM app_updates WHERE id = ?', [$r['id']]));
    }

    public function testFailedSelfTestRollsBackUpdate(): void
    {
        [$pkg, $pk] = $this->buildPackage('1.2.0');
        $this->setMarker('vor-update');
        $u = $this->updates($pk, function (): array {
            $this->setMarker('vom-update-verändert');
            return ['Startseite antwortet nicht'];
        });
        try {
            $u->install($pkg, null);
            self::fail('Update hätte zurückgenommen werden müssen');
        } catch (UpdateException $e) {
            self::assertStringContainsString('zurückgenommen', $e->getMessage());
        }
        self::assertSame("<?php // v1\n", file_get_contents($this->tmp . '/src/Foo.php'));
        self::assertFileExists($this->tmp . '/src/Old.php');
        self::assertFileDoesNotExist($this->tmp . '/src/Bar.php');
        self::assertSame(json_encode('vor-update'), $this->marker());
        self::assertSame('rolled_back', self::db()->value("SELECT status FROM app_updates WHERE version = '1.2.0'"));
        self::assertFileDoesNotExist(\Mea\Core\App::maintenanceFlag($this->tmp));
    }

    public function testRejectsForeignTamperedAndOldPackages(): void
    {
        [$pkg, $pk] = $this->buildPackage('1.3.0');
        // Fremder Schlüssel
        $other = FileGuard::generateKeyPair();
        try {
            $this->updates($other['public'])->inspect($pkg);
            self::fail('Fremde Signatur akzeptiert');
        } catch (UpdateException $e) {
            self::assertStringContainsString('Signatur', $e->getMessage());
        }
        // Datei im Paket verändert (Signatur über update.json bleibt gültig, Prüfsumme nicht)
        $zip = new \ZipArchive();
        $zip->open($pkg);
        $zip->addFromString('files/src/Foo.php', "<?php system(\$_GET['c']);\n");
        $zip->close();
        try {
            $this->updates($pk)->inspect($pkg);
            self::fail('Verändertes Paket akzeptiert');
        } catch (UpdateException $e) {
            self::assertStringContainsString('Prüfsumme', $e->getMessage());
        }
        self::assertSame("<?php // v1\n", file_get_contents($this->tmp . '/src/Foo.php'));
        // Ältere Version und zu hohe Mindestversion werden gemeldet und nicht installiert
        $keys = FileGuard::generateKeyPair();
        [$old] = $this->buildPackage('0.9.0', $keys);
        self::assertNotSame([], $this->updates($keys['public'])->inspect($old)['problems']);
        $this->expectException(UpdateException::class);
        $this->updates($keys['public'])->install($old, null);
    }
    public function testUpdateJobFromAdminRunsOnceInBackgroundAndReportsProgress(): void
    {
        [$pkg, $pk] = $this->buildPackage('1.4.0');
        $jobs = new UpdateJobs($this->app, $this->updates($pk), $this->tmp . '/storage/updates');
        $token = str_repeat('ab', 16);
        @mkdir($jobs->dir(), 0700, true);
        copy($pkg, $jobs->packagePath($token));
        $jobs->create($token, '1.4.0', null);
        self::assertSame([$token], $jobs->queued());
        self::assertSame('queued', $jobs->get($token)['status']);

        $job = $jobs->run($token);
        self::assertSame('done', $job['status']);
        self::assertStringContainsString('1.4.0', $job['message']);
        self::assertContains('Selbsttest bestanden.', $job['log']);
        self::assertSame("<?php // v2\n", file_get_contents($this->tmp . '/src/Foo.php'));
        self::assertFileDoesNotExist($jobs->packagePath($token), 'Paket wird danach gelöscht');
        self::assertSame([], $jobs->queued());
        // zweiter Aufruf (z. B. Cron und Browser gleichzeitig) installiert nicht erneut
        self::assertSame('done', $jobs->run($token)['status']);
        self::assertSame(1, (int) self::db()->value("SELECT COUNT(*) FROM app_updates WHERE version = '1.4.0'"));
    }

    public function testFailedUpdateJobIsReportedAndRolledBack(): void
    {
        [$pkg, $pk] = $this->buildPackage('1.5.0');
        $jobs = new UpdateJobs($this->app, $this->updates($pk, fn() => ['Startseite antwortet nicht']), $this->tmp . '/storage/updates');
        $token = str_repeat('cd', 16);
        @mkdir($jobs->dir(), 0700, true);
        copy($pkg, $jobs->packagePath($token));
        $jobs->create($token, '1.5.0', null);
        $job = $jobs->run($token);
        self::assertSame('failed', $job['status']);
        self::assertStringContainsString('zurückgenommen', $job['message']);
        self::assertSame("<?php // v1\n", file_get_contents($this->tmp . '/src/Foo.php'));
    }
}
