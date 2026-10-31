<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Core\App;

/**
 * Update-Aufträge aus der Verwaltung. Die Installation läuft getrennt von der Seite, die sie anstößt:
 * entweder im minütlichen Cron (keine Zeitgrenzen) oder im Hintergrund nach dem Absenden der Antwort.
 * Den Fortschritt liest die Verwaltung aus einer kleinen Statusdatei, damit ein Abbruch der
 * Browserverbindung (z. B. nach 60 Sekunden beim Webserver) die Installation nicht stört.
 *
 * Dateien in storage/updates: incoming-<token>.zip (Paket), job-<token>.json (Status).
 */
final class UpdateJobs
{
    public function __construct(private App $app, private ?UpdateService $updates = null, private ?string $baseDir = null) {}

    public function dir(): string
    {
        $d = $this->baseDir ?? $this->app->root . '/storage/updates';
        if (!is_dir($d)) {
            mkdir($d, 0700, true);
        }
        return $d;
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    public function packagePath(string $token): string
    {
        return $this->dir() . '/incoming-' . $token . '.zip';
    }

    private function jobPath(string $token): string
    {
        return $this->dir() . '/job-' . $token . '.json';
    }

    public function create(string $token, string $version, ?int $userId): void
    {
        $this->write($token, ['token' => $token, 'status' => 'queued', 'version' => $version, 'from' => $this->app->version(), 'user_id' => $userId,
            'created_at' => time(), 'updated_at' => time(), 'log' => ['Wartet auf den Start …'], 'message' => '']);
    }

    /** @return array<string,mixed>|null */
    public function get(string $token): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $raw = @file_get_contents($this->jobPath($token));
        $j = $raw === false ? null : json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /** Wartende Aufträge, die seit mindestens $minAge Sekunden auf den Start warten. @return list<string> */
    public function queued(int $minAge = 0): array
    {
        $out = [];
        foreach (glob($this->dir() . '/job-*.json') ?: [] as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (is_array($j) && ($j['status'] ?? '') === 'queued' && time() - (int) ($j['created_at'] ?? 0) >= $minAge) {
                $out[] = (string) $j['token'];
            }
        }
        return $out;
    }

    /** Auftrag ausführen, falls er noch wartet (nur einmal, auch bei gleichzeitigen Aufrufen). */
    public function run(string $token): ?array
    {
        $lockFile = $this->dir() . '/job-' . $token . '.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null; // läuft schon
        }
        try {
            $job = $this->get($token);
            if ($job === null || $job['status'] !== 'queued') {
                return $job;
            }
            $job['status'] = 'running';
            $job['started_at'] = time();
            $job['log'] = ['Update startet …'];
            $this->write($token, $job);
            $path = $this->packagePath($token);
            try {
                if (!is_file($path)) {
                    throw new UpdateException('Das Paket ist nicht mehr vorhanden. Bitte erneut hochladen.');
                }
                $r = ($this->updates ?? $this->app->updates())->install($path, $job['user_id'] !== null ? (int) $job['user_id'] : null, function (array $log) use ($token, &$job): void {
                    $job['log'] = $log;
                    $this->write($token, $job);
                });
                $job['status'] = 'done';
                $job['log'] = $r['log'];
                $job['message'] = 'Update auf Version ' . $job['version'] . ' installiert.';
                $this->app->audit->record('update.installed', 'Update installiert (Version ' . $job['version'] . ')', 'update', $r['id']);
            } catch (\Throwable $e) {
                $job['status'] = 'failed';
                $job['message'] = $e->getMessage();
                $last = $this->app->db->value('SELECT log FROM app_updates ORDER BY id DESC LIMIT 1');
                if (is_string($last) && $last !== '') {
                    $job['log'] = explode("\n", $last);
                }
                $this->app->audit->record('update.failed', $e->getMessage(), 'update', null);
                try {
                    (new Notifier($this->app->db, $this->app->mailer))->alert('Update fehlgeschlagen', $e->getMessage(), 'update-' . $token);
                } catch (\Throwable) {
                }
            } finally {
                @unlink($path);
            }
            $job['finished_at'] = time();
            $this->write($token, $job);
            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
        }
    }

    /** Alte Aufträge, Pakete und abgebrochene Uploads nach einem Tag entfernen. */
    public function cleanup(int $maxAge = 86400): int
    {
        $n = 0;
        foreach (array_merge(glob($this->dir() . '/job-*.json') ?: [], glob($this->dir() . '/incoming-*') ?: [], glob($this->dir() . '/upload-*') ?: []) as $f) {
            if (filemtime($f) < time() - $maxAge) {
                $j = str_ends_with($f, '.json') ? json_decode((string) @file_get_contents($f), true) : null;
                if (is_array($j) && in_array($j['status'] ?? '', ['queued', 'running'], true)) {
                    continue;
                }
                @unlink($f);
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $job */
    private function write(string $token, array $job): void
    {
        $job['updated_at'] = time();
        $tmp = $this->jobPath($token) . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->jobPath($token));
    }
}
