<?php
declare(strict_types=1);

namespace Mea\Module\Mail;

use Mea\Core\Database;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Versendet fällige Nachrichten aus der Warteschlange. Jede Nachricht wird vorher
 * gesperrt, damit parallele Läufe (Cron, Klick) sie nicht doppelt senden.
 * Fehlversuche werden mit wachsendem Abstand wiederholt, höchstens MAX_ATTEMPTS-mal.
 */
final class OutboxWorker
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private Database $db,
        private ?TransportInterface $transport,
        private string $fromEmail,
        private string $fromName,
    ) {}

    public function isConfigured(): bool
    {
        return $this->transport !== null && filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array{sent:int, failed:int} */
    public function run(int $limit = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if (!$this->isConfigured()) {
            return $result;
        }
        $ids = array_column($this->db->all(
            "SELECT id FROM email_outbox
             WHERE (status = 'queued' OR (status = 'sending' AND locked_until < UTC_TIMESTAMP()))
               AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, min(200, $limit)),
        ), 'id');
        foreach ($ids as $id) {
            // Sperre übernehmen; nur wer die Zeile ändert, darf senden
            $claimed = $this->db->run(
                "UPDATE email_outbox SET status = 'sending', locked_until = UTC_TIMESTAMP() + INTERVAL 5 MINUTE, attempts = attempts + 1
                 WHERE id = ? AND (status = 'queued' OR (status = 'sending' AND locked_until < UTC_TIMESTAMP()))",
                [$id],
            );
            if ($claimed !== 1) {
                continue;
            }
            $this->sendOne((int) $id) ? $result['sent']++ : $result['failed']++;
        }
        return $result;
    }

    public function sendOne(int $id): bool
    {
        $m = $this->db->one('SELECT * FROM email_outbox WHERE id = ?', [$id]);
        if ($m === null || $this->transport === null) {
            return false;
        }
        try {
            $email = (new Email())
                ->from(new Address($this->fromEmail, $this->fromName))
                ->to(new Address((string) $m['to_email'], (string) ($m['to_name'] ?? '')))
                ->subject((string) $m['subject'])
                ->html((string) $m['body_html'])
                ->text((string) $m['body_text']);
            $email->getHeaders()->addTextHeader('X-Mea-Message', (string) $m['id']);
            $this->transport->send($email);
            $this->db->run("UPDATE email_outbox SET status = 'sent', sent_at = UTC_TIMESTAMP(), locked_until = NULL, last_error = NULL WHERE id = ?", [$id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int) $m['attempts'];
            $final = $attempts >= self::MAX_ATTEMPTS;
            $delay = min(360, 5 * (2 ** max(0, $attempts - 1))); // 5, 10, 20, 40 … Minuten
            $this->db->run(
                'UPDATE email_outbox SET status = ?, locked_until = NULL, last_error = ?, next_attempt_at = UTC_TIMESTAMP() + INTERVAL ? MINUTE WHERE id = ?',
                [$final ? 'failed' : 'queued', mb_substr(self::safeError($e), 0, 255), $delay, $id],
            );
            return false;
        }
    }

    /** Fehlgeschlagene Nachricht erneut einreihen (Verwaltung). */
    public function retry(int $id): void
    {
        $this->db->run("UPDATE email_outbox SET status = 'queued', attempts = 0, next_attempt_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ? AND status = 'failed'", [$id]);
    }

    /** Zugangsdaten aus Fehlermeldungen entfernen. */
    private static function safeError(\Throwable $e): string
    {
        return preg_replace('#//[^@\s]+@#', '//***@', $e->getMessage()) ?? 'Versandfehler';
    }
}
