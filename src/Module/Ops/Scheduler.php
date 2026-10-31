<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Admin\Resources\HolidayResource;
use Mea\Admin\SystemCheck;
use Mea\Core\App;
use Mea\Install\EmailTemplateSeeder;

/**
 * Wartungsprozess (Aufruf jede Minute per Cron). Jeder Job hat einen Takt, eine eigene Sperre
 * und einen Eintrag in cron_runs. Jobs sind wiederholbar: Sie arbeiten mit Zuständen und
 * Idempotenzschlüsseln, sodass ein erneuter Lauf keine doppelten Buchungen oder E-Mails erzeugt.
 */
final class Scheduler
{
    public const NIGHT_HOUR = 2; // Ortszeit Deutschland
    /** Job => [Takt, Beschreibung] */
    public const JOBS = [
        'updates' => ['minute', 'Updates aus der Verwaltung installieren'],
        'reservations' => ['minute', 'Abgelaufene Reservierungen freigeben'],
        'mail' => ['minute', 'E-Mails versenden'],
        'cleanup' => ['hourly', 'Abgelaufene Tokens, Sitzungen, Sperren und temporäre Dateien entfernen'],
        'backup' => ['nightly', 'Nächtliche Sicherung und Aufräumen alter Sicherungen'],
        'retention' => ['nightly', 'Anonymisierung und Löschung nach Fristen'],
        'reminders' => ['nightly', 'Terminerinnerungen an Teilnehmende'],
        'integrity' => ['nightly', 'Dateiwächter'],
        'holidays' => ['nightly', 'Feiertage für das Folgejahr anlegen'],
        'health' => ['nightly', 'Systemprüfung und Meldung kritischer Fehler'],
        'google_reviews' => ['nightly', 'Google-Bewertungen abrufen'],
        'backup_reminder' => ['monthly', 'Erinnerung: Sicherung extern speichern'],
    ];

    public function __construct(private App $app) {}

    /** @return array<string,string> Job => Ergebnis (nur ausgeführte Jobs) */
    public function run(?\DateTimeImmutable $now = null, ?array $only = null): array
    {
        $now ??= $this->app->clock->now();
        $results = [];
        foreach (self::JOBS as $job => [$freq]) {
            if ($only !== null && !in_array($job, $only, true)) {
                continue;
            }
            if ($only === null && !$this->due($job, $freq, $now)) {
                continue;
            }
            $results[$job] = $this->runJob($job, $now);
        }
        $this->app->settings->set('cron_last_run', $now->format('Y-m-d H:i:s'));
        return $results;
    }

    private function due(string $job, string $freq, \DateTimeImmutable $now): bool
    {
        $r = $this->app->db->one('SELECT * FROM cron_runs WHERE job = ?', [$job]);
        if ($freq === 'minute') {
            return true;
        }
        $last = $r && $r['last_success_at'] ? new \DateTimeImmutable($r['last_success_at'], new \DateTimeZone('UTC')) : null;
        // Nach einem Fehler frühestens 30 Minuten später erneut versuchen
        if ($r && $r['last_status'] === 'failed' && $r['last_started_at'] && $now->getTimestamp() - strtotime($r['last_started_at'] . ' UTC') < 1800) {
            return false;
        }
        $local = $now->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $lastLocal = $last?->setTimezone(new \DateTimeZone('Europe/Berlin'));
        return match ($freq) {
            'hourly' => $last === null || $now->getTimestamp() - $last->getTimestamp() >= 3600,
            'nightly' => (int) $local->format('G') >= self::NIGHT_HOUR && ($lastLocal === null || $lastLocal->format('Y-m-d') !== $local->format('Y-m-d')),
            'monthly' => (int) $local->format('G') >= self::NIGHT_HOUR && ($lastLocal === null || $lastLocal->format('Y-m') !== $local->format('Y-m')),
            default => false,
        };
    }

    private function runJob(string $job, \DateTimeImmutable $now): string
    {
        $db = $this->app->db;
        if ((int) $db->value('SELECT GET_LOCK(?, 0)', ['mea-job-' . $job]) !== 1) {
            return 'läuft bereits';
        }
        $start = microtime(true);
        $db->run("INSERT INTO cron_runs (job, last_started_at, last_status) VALUES (?, ?, 'running')
                  ON DUPLICATE KEY UPDATE last_started_at = VALUES(last_started_at), last_status = 'running'", [$job, $now->format('Y-m-d H:i:s')]);
        try {
            $msg = $this->{'job' . str_replace('_', '', ucwords($job, '_'))}($now);
            $db->run("UPDATE cron_runs SET last_status = 'ok', last_success_at = ?, last_finished_at = UTC_TIMESTAMP(), last_message = ?, duration_ms = ? WHERE job = ?",
                [$now->format('Y-m-d H:i:s'), mb_substr($msg, 0, 255), (int) ((microtime(true) - $start) * 1000), $job]);
            return $msg;
        } catch (\Throwable $e) {
            $this->app->log->error("Wartungsjob $job fehlgeschlagen", ['error' => $e->getMessage()]);
            $db->run("UPDATE cron_runs SET last_status = 'failed', last_finished_at = UTC_TIMESTAMP(), last_message = ?, duration_ms = ? WHERE job = ?",
                [mb_substr($e->getMessage(), 0, 255), (int) ((microtime(true) - $start) * 1000), $job]);
            if (!in_array(self::JOBS[$job][0], ['minute'], true) || $job === 'mail') {
                $this->notifier()->alert('Wartungsjob fehlgeschlagen: ' . self::JOBS[$job][1], 'Der automatische Wartungsjob ist fehlgeschlagen. Er wird später erneut versucht.',
                    'job-' . $job . '-' . $now->format('Y-m-d'), $e->getMessage());
            }
            return 'Fehler: ' . $e->getMessage();
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', ['mea-job-' . $job]);
        }
    }

    private function notifier(): Notifier
    {
        return new Notifier($this->app->db, $this->app->mailer);
    }

    private function jobGoogleReviews(\DateTimeImmutable $now): string
    {
        $g = $this->app->googleReviews();
        if (!$g->isConfigured()) {
            return 'nicht eingerichtet';
        }
        $r = $g->sync($now);
        return ($r['rating'] === null ? 'ohne Gesamtwert' : number_format($r['rating'], 1, ',', '') . ' Sterne, ' . $r['count'] . ' Bewertungen') . ', ' . $r['reviews'] . ' Texte';
    }

    private function jobUpdates(): string
    {
        $jobs = new UpdateJobs($this->app);
        $done = [];
        foreach ($jobs->queued() as $token) {
            $j = $jobs->run($token);
            if ($j !== null) {
                $done[] = $j['version'] . ': ' . ($j['status'] === 'done' ? 'installiert' : 'fehlgeschlagen');
            }
        }
        return $done === [] ? 'nichts zu tun' : implode(', ', $done);
    }

    private function jobReservations(): string
    {
        return $this->app->bookings()->expireReservations() . ' freigegeben';
    }

    private function jobMail(): string
    {
        $w = $this->app->outbox();
        if (!$w->isConfigured()) {
            return 'Versand nicht eingerichtet';
        }
        $r = $w->run(50);
        return "{$r['sent']} gesendet, {$r['failed']} fehlgeschlagen";
    }

    private function jobCleanup(\DateTimeImmutable $now): string
    {
        $db = $this->app->db;
        $t = $now->format('Y-m-d H:i:s');
        $n = $db->run('DELETE FROM user_tokens WHERE expires_at < ? - INTERVAL 7 DAY OR used_at < ? - INTERVAL 7 DAY OR revoked_at < ? - INTERVAL 7 DAY', [$t, $t, $t]);
        $n += $db->run('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ? - INTERVAL 30 DAY) OR last_seen_at < ? - INTERVAL 30 DAY', [$t, $t]);
        $n += $db->run('DELETE FROM rate_limits WHERE window_start < ? - INTERVAL 1 DAY', [$t]);
        $n += $db->run('DELETE FROM ip_rules WHERE expires_at IS NOT NULL AND expires_at < ? - INTERVAL 30 DAY', [$t]);
        $n += $db->run('DELETE FROM security_events WHERE created_at < ? - INTERVAL 90 DAY', [$t]);
        $n += $db->run('DELETE FROM not_found_log WHERE last_seen_at < ? - INTERVAL 180 DAY', [$t]);
        $files = (new UpdateJobs($this->app))->cleanup();
        foreach (['storage/tmp' => 86400, 'storage/mail' => 7 * 86400] as $dir => $age) {
            foreach (glob($this->app->root . '/' . $dir . '/*') ?: [] as $f) {
                if (filemtime($f) < $now->getTimestamp() - $age) {
                    is_dir($f) ? $this->app->backups()->removeDir($f) : @unlink($f);
                    $files++;
                }
            }
        }
        return "$n Datensätze, $files temporäre Dateien entfernt";
    }

    private function jobBackup(): string
    {
        $b = $this->app->backups();
        $cfg = (array) $this->app->settings->get('backup', []);
        $id = $b->create('nightly');
        $pruned = $b->prune(max(3, (int) ($cfg['keep_days'] ?? 14)), max(7, (int) ($cfg['keep_manual_days'] ?? 90)));
        return "Sicherung #$id erstellt, $pruned alte entfernt";
    }

    private function jobRetention(\DateTimeImmutable $now): string
    {
        $db = $this->app->db;
        $r = (array) $this->app->settings->get('retention', []);
        $t = $now->format('Y-m-d H:i:s');
        $inq = $db->run("UPDATE inquiries SET name = 'Anonymisiert', email = 'anonym@invalid', phone = NULL, message = '[anonymisiert]', anonymized_at = ?
                         WHERE anonymized_at IS NULL AND created_at < ? - INTERVAL ? MONTH", [$t, $t, max(1, (int) ($r['inquiries_months'] ?? 12))]);
        $anon = "first_name = 'Anonymisiert', last_name = '', salutation = NULL, email = 'anonym@invalid', phone = NULL, birth_date = NULL,
                 birth_place = NULL, street = NULL, postal_code = NULL, city = NULL, extra_json = NULL, anonymized_at = ?";
        // Abgebrochene Anmeldungen ohne Zahlung
        $unpaid = $db->run("UPDATE booking_participants p JOIN bookings b ON b.id = p.booking_id SET $anon
                            WHERE p.anonymized_at IS NULL AND b.status IN ('expired','cancelled') AND b.payment_status IN ('none','failed')
                              AND b.created_at < ? - INTERVAL ? DAY", [$t, $t, max(1, (int) ($r['unpaid_bookings_days'] ?? 30))]);
        // Bezahlte Buchungen erst nach der gesetzlichen Aufbewahrungsfrist (ab Ende des Prüfungsjahres)
        $years = max(6, (int) ($r['bookings_years'] ?? 10));
        $paid = $db->run("UPDATE booking_participants p JOIN bookings b ON b.id = p.booking_id JOIN exam_sessions s ON s.id = b.exam_session_id SET $anon
                          WHERE p.anonymized_at IS NULL AND MAKEDATE(YEAR(s.starts_at) + 1, 1) < ? - INTERVAL ? YEAR", [$t, $t, $years]);
        $db->run("UPDATE payment_events SET payload_json = '{}' WHERE created_at < ? - INTERVAL ? YEAR", [$t, $years]);
        $mail = $db->run("DELETE FROM email_outbox WHERE status IN ('sent','failed') AND created_at < ? - INTERVAL ? DAY", [$t, max(30, (int) ($r['email_log_days'] ?? 180))]);
        $audit = $db->run('DELETE FROM audit_log WHERE created_at < ? - INTERVAL ? YEAR', [$t, max(1, (int) ($r['audit_years'] ?? 3))]);
        // Einwilligungsnachweise: 3 Jahre (übliche Verjährungsfrist), danach löschen
        $consent = $db->run('DELETE FROM consent_log WHERE created_at < ? - INTERVAL 3 YEAR', [$t]);
        // Bewerbungen: 6 Monate nach Abschluss, nie abgeschlossene nach 12 Monaten (samt Unterlagen)
        $apps = $this->app->applications()->purge($now);
        return "Anfragen $inq, unbezahlt $unpaid, bezahlt $paid anonymisiert; $mail E-Mails, $audit Protokolleinträge, $consent Einwilligungsnachweise, $apps Bewerbungen gelöscht";
    }

    private function jobReminders(\DateTimeImmutable $now): string
    {
        $days = (int) (((array) $this->app->settings->get('reminders', []))['days_before'] ?? 3);
        $days = $days > 0 ? $days : 3;
        $from = $now->format('Y-m-d H:i:s');
        $to = $now->modify("+$days days")->format('Y-m-d H:i:s');
        $n = 0;
        foreach ($this->app->db->all(
            "SELECT b.id, p.id AS pid FROM bookings b JOIN booking_participants p ON p.booking_id = b.id JOIN exam_sessions s ON s.id = b.exam_session_id
             WHERE b.status = 'confirmed' AND s.status IN ('open','closed') AND s.starts_at BETWEEN ? AND ? AND p.reminder_sent_at IS NULL AND p.anonymized_at IS NULL",
            [$from, $to],
        ) as $row) {
            $this->app->bookings()->sendReminder((int) $row['id']);
            $this->app->db->run('UPDATE booking_participants SET reminder_sent_at = ? WHERE id = ?', [$from, $row['pid']]);
            $n++;
        }
        return "$n Erinnerungen";
    }

    private function jobIntegrity(\DateTimeImmutable $now): string
    {
        $r = $this->app->fileGuard()->check();
        $diff = array_merge(
            array_map(fn($p) => 'geändert: ' . $p, $r['changed']),
            array_map(fn($p) => 'fehlt: ' . $p, $r['missing']),
            array_map(fn($p) => 'unerwartet: ' . $p, $r['unexpected']),
        );
        $status = $r['status'] === 'no_reference' && $r['unexpected'] !== [] ? 'changed' : $r['status'];
        $this->app->db->insert('integrity_runs', ['status' => $status, 'reference_version' => $r['version'], 'checked_files' => $r['checked'],
            'changed_json' => $diff ? json_encode(array_slice($diff, 0, 500), JSON_UNESCAPED_SLASHES) : null]);
        if (in_array($status, ['changed', 'invalid_reference'], true)) {
            $this->app->db->insert('security_events', ['event_type' => 'integrity', 'severity' => 'critical', 'points' => 0,
                'detail' => mb_substr(($status === 'invalid_reference' ? 'Referenz ungültig' : count($diff) . ' Abweichungen'), 0, 255)]);
            $this->notifier()->alert(
                'Dateiwächter: Programmdateien verändert',
                $status === 'invalid_reference'
                    ? 'Die Signatur der Referenzprüfsummen ist ungültig. Möglicherweise wurde die Referenz manipuliert.'
                    : 'Der Dateiwächter hat veränderte, fehlende oder unerwartete Dateien gefunden. Bitte prüfen Sie die Systemprüfung in der Verwaltung.',
                'integrity-' . $now->format('Y-m-d') . '-' . substr(hash('sha256', implode("\n", $diff)), 0, 12),
                implode("\n", array_slice($diff, 0, 30)),
            );
        }
        return $status . ' (' . count($diff) . ' Abweichungen)';
    }

    private function jobHolidays(\DateTimeImmutable $now): string
    {
        $n = 0;
        $year = (int) $now->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y');
        foreach ([$year, $year + 1] as $y) {
            foreach (HolidayResource::nrw($y) as $date => $name) {
                $n += $this->app->db->run("INSERT IGNORE INTO holidays (holiday_date, name, region) VALUES (?, ?, 'NW')", [$date, $name]);
            }
        }
        return "$n Feiertage ergänzt";
    }

    private function jobHealth(\DateTimeImmutable $now): string
    {
        (new EmailTemplateSeeder($this->app->db))->run();
        $errors = array_values(array_filter((new SystemCheck($this->app))->run(), fn($r) => $r['status'] === 'error'));
        if ($errors !== []) {
            $this->notifier()->alert('Systemprüfung meldet Fehler', 'Die nächtliche Systemprüfung hat Fehler gefunden. Details stehen in der Verwaltung unter Systemprüfung.',
                'health-' . $now->format('Y-m-d'), implode("\n", array_map(fn($r) => $r['label'] . ': ' . $r['detail'], $errors)));
        }
        return count($errors) . ' Fehler';
    }

    private function jobBackupReminder(\DateTimeImmutable $now): string
    {
        $last = $this->app->db->value('SELECT MAX(downloaded_at) FROM backups');
        if ($last !== null && $now->getTimestamp() - strtotime($last . ' UTC') < 30 * 86400) {
            return 'nicht nötig';
        }
        $this->notifier()->alert('Bitte eine Sicherung extern speichern',
            'Eine Sicherung auf demselben Server schützt nicht vor einem Serverausfall. Bitte laden Sie in der Verwaltung unter „Sicherungen“ die neueste Sicherung herunter und bewahren Sie sie getrennt auf (z. B. verschlüsselter USB-Stick oder Cloud-Speicher).',
            'backup-reminder-' . $now->format('Y-m'));
        return 'Erinnerung verschickt';
    }

    /** @return list<array<string,mixed>> Stand aller Jobs für die Systemprüfung */
    public function status(): array
    {
        $rows = [];
        foreach ($this->app->db->all('SELECT * FROM cron_runs') as $r) {
            $rows[$r['job']] = $r;
        }
        $out = [];
        foreach (self::JOBS as $job => [$freq, $label]) {
            $out[] = ['job' => $job, 'label' => $label, 'freq' => $freq] + ($rows[$job] ?? ['last_status' => null, 'last_success_at' => null, 'last_message' => null]);
        }
        return $out;
    }
}
