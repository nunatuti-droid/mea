<?php
declare(strict_types=1);

namespace Mea\Admin;

use Mea\Core\App;

/** Prüft Server, Konfiguration und Dienste. Ergebnisse: ok, warn, error. */
final class SystemCheck
{
    public function __construct(private App $app) {}

    /** @return list<array{group:string, label:string, status:'ok'|'warn'|'error', detail:string}> */
    public function run(): array
    {
        $c = $this->app->config;
        $root = $this->app->root;
        $out = [];
        $add = function (string $group, string $label, string $status, string $detail) use (&$out): void {
            $out[] = ['group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        $add('Server', 'PHP-Version', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'error', PHP_VERSION . ' (benötigt: 8.3 oder neuer)');
        foreach (['pdo_mysql', 'intl', 'mbstring', 'gd', 'sodium', 'openssl', 'curl', 'fileinfo', 'json'] as $ext) {
            $add('Server', "Erweiterung $ext", extension_loaded($ext) ? 'ok' : 'error', extension_loaded($ext) ? 'geladen' : 'fehlt');
        }
        $webp = function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false);
        $add('Server', 'WebP-Unterstützung (GD)', $webp ? 'ok' : 'error', $webp ? 'vorhanden' : 'fehlt – Bildvarianten können nicht erzeugt werden');
        $add('Server', 'Upload-Grenze', 'ok', 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'));
        $free = @disk_free_space($root);
        $add('Server', 'Freier Speicher', $free === false ? 'warn' : ($free < 1024 ** 3 ? 'warn' : 'ok'), $free === false ? 'nicht ermittelbar' : number_format($free / 1024 ** 3, 1, ',', '.') . ' GB');

        try {
            $ver = (string) $this->app->db->value('SELECT VERSION()');
            $add('Datenbank', 'Verbindung', 'ok', $ver);
            $pending = $this->pendingMigrations();
            $add('Datenbank', 'Migrationen', $pending === 0 ? 'ok' : 'warn', $pending === 0 ? 'aktuell' : "$pending ausstehend – bin/console migrate ausführen");
        } catch (\Throwable $e) {
            $add('Datenbank', 'Verbindung', 'error', 'keine Verbindung');
        }

        foreach (['storage/logs', 'storage/uploads', 'storage/cache', 'public/media'] as $dir) {
            $path = $root . '/' . $dir;
            @mkdir($path, 0750, true);
            $add('Dateien', "Schreibrecht $dir", is_writable($path) ? 'ok' : 'error', is_writable($path) ? 'beschreibbar' : 'nicht beschreibbar');
        }
        $envPerm = @fileperms($root . '/.env');
        $add('Dateien', '.env-Datei', $envPerm === false ? 'warn' : (($envPerm & 0o004) ? 'warn' : 'ok'), $envPerm === false ? 'nicht gefunden (Umgebungsvariablen des Servers?)' : (($envPerm & 0o004) ? 'für alle lesbar – Rechte auf 600 setzen' : 'geschützt'));

        $env = (string) $c->get('APP_ENV', 'production');
        $add('Konfiguration', 'Umgebung', 'ok', $env);
        $add('Konfiguration', 'Fehleranzeige', $env === 'production' && $c->bool('APP_DEBUG') ? 'error' : 'ok', $c->bool('APP_DEBUG') ? 'APP_DEBUG an' : 'aus');
        $add('Konfiguration', 'HTTPS-Adresse', str_starts_with((string) $c->get('APP_URL'), 'https://') ? 'ok' : ($env === 'production' ? 'error' : 'warn'), (string) $c->get('APP_URL'));
        $add('Konfiguration', 'Suchmaschinen', $c->bool('APP_NOINDEX') ? 'warn' : 'ok', $c->bool('APP_NOINDEX') ? 'ausgesperrt (APP_NOINDEX=true) – vor dem Livegang ändern' : 'erlaubt');
        $demo = (bool) $this->app->settings->get('demo_notice', false);
        $add('Konfiguration', 'Beispieldaten', $demo ? 'warn' : 'ok', $demo ? 'Vorschau-Hinweis aktiv – Beispieltermine vor dem Livegang entfernen' : 'keine');

        $outbox = $this->app->outbox();
        $add('E-Mail', 'Versand (SMTP)', $outbox->isConfigured() ? 'ok' : 'warn', $outbox->isConfigured() ? 'eingerichtet' : 'MAIL_DSN oder MAIL_FROM fehlt – E-Mails bleiben in der Warteschlange');
        $failed = (int) $this->app->db->value("SELECT COUNT(*) FROM email_outbox WHERE status = 'failed'");
        $add('E-Mail', 'Fehlgeschlagene Nachrichten', $failed > 0 ? 'warn' : 'ok', (string) $failed);
        $stuck = (int) $this->app->db->value("SELECT COUNT(*) FROM email_outbox WHERE status = 'queued' AND next_attempt_at < UTC_TIMESTAMP() - INTERVAL 30 MINUTE");
        $add('E-Mail', 'Warteschlange', $stuck > 0 ? 'warn' : 'ok', $stuck > 0 ? "$stuck Nachrichten warten länger als 30 Minuten – läuft der Cronjob?" : 'in Ordnung');

        $pp = $this->app->paymentGateway();
        $add('Zahlung', 'PayPal', $pp->isConfigured() ? 'ok' : 'warn', $pp->isConfigured() ? 'eingerichtet (' . $pp->mode() . ')' : 'Zugangsdaten fehlen – Online-Buchung ist gesperrt');
        if ($pp->isConfigured()) {
            $add('Zahlung', 'PayPal-Webhook', $c->get('PAYPAL_WEBHOOK_ID') ? 'ok' : 'warn', $c->get('PAYPAL_WEBHOOK_ID') ? 'Webhook-ID hinterlegt' : 'PAYPAL_WEBHOOK_ID fehlt – Zahlungen werden nur über die Rückleitung bestätigt');
            $add('Zahlung', 'Modus', $env === 'production' && $pp->mode() !== 'live' ? 'warn' : 'ok', $pp->mode() === 'live' ? 'Live' : 'Sandbox (Testgeld)');
        }

        $cron = $this->app->settings->get('cron_last_run');
        $cronOk = is_string($cron) && time() - (int) strtotime($cron . ' UTC') < 900;
        $add('Betrieb', 'Cronjob', $cronOk ? 'ok' : 'warn', is_string($cron) ? 'zuletzt ' . self::local($cron) : 'noch nie gelaufen – Cronjob einrichten (siehe README)');
        $add('Betrieb', 'Backups und Updates', 'warn', 'werden in Phase 5 eingerichtet');
        return $out;
    }

    /** @return list<array{label:string, detail:string, status:string}> nur Warnungen und Fehler */
    public function warnings(): array
    {
        return array_values(array_filter($this->run(), fn($r) => $r['status'] !== 'ok'));
    }

    private function pendingMigrations(): int
    {
        $files = glob($this->app->root . '/migrations/*.php') ?: [];
        $done = array_map('strval', array_column($this->app->db->all('SELECT version FROM schema_migrations'), 'version'));
        $pending = 0;
        foreach ($files as $f) {
            if (preg_match('/^(\d{14})_/', basename($f), $m) && !in_array($m[1], $done, true)) {
                $pending++;
            }
        }
        return $pending;
    }

    private static function local(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
    }
}
