<?php
declare(strict_types=1);

namespace Mea\Module\Mail;

use Mea\Core\Database;
use Mea\Module\Content\SettingsRepository;

/**
 * Legt E-Mails in die Warteschlange. Der Idempotenzschlüssel verhindert doppelte
 * Nachrichten, wenn dasselbe Ereignis mehrfach verarbeitet wird.
 */
final class Mailer
{
    public function __construct(
        private Database $db,
        private TemplateRenderer $renderer,
        private SettingsRepository $settings,
        private string $baseUrl,
    ) {}

    /**
     * @param array<string,mixed> $vars
     * @return bool true, wenn neu eingereiht; false, wenn es diese Nachricht schon gibt
     */
    public function queue(string $templateKey, string $toEmail, ?string $toName, array $vars, string $idempotencyKey, ?string $relatedType = null, ?int $relatedId = null): bool
    {
        if ($this->db->value('SELECT 1 FROM email_outbox WHERE idempotency_key = ?', [$idempotencyKey]) !== null) {
            return false;
        }
        $msg = $this->renderer->render($templateKey, $vars + $this->commonVars());
        try {
            $this->db->insert('email_outbox', [
                'idempotency_key' => mb_substr($idempotencyKey, 0, 120),
                'template_key' => $templateKey,
                'to_email' => $toEmail,
                'to_name' => $toName,
                'subject' => mb_substr($msg['subject'], 0, 200),
                'body_html' => $msg['html'],
                'body_text' => $msg['text'],
                'status' => 'queued',
                'next_attempt_at' => gmdate('Y-m-d H:i:s'),
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false; // gleichzeitiger Aufruf hat sie schon angelegt
            }
            throw $e;
        }
        return true;
    }

    /** Empfängeradresse für interne Benachrichtigungen (Büro). */
    public function officeAddress(): ?string
    {
        $company = $this->settings->get('company', []);
        $mail = $this->settings->get('notifications', [])['office_email'] ?? ($company['email'] ?? null);
        return is_string($mail) && filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : null;
    }

    /** @return array<string,mixed> */
    public function commonVars(): array
    {
        $c = $this->settings->get('company', []);
        return [
            'firma' => [
                'name' => $c['name'] ?? 'MEA Bildungszentrum',
                'telefon' => $c['phone_display'] ?? ($c['phone'] ?? ''),
                'email' => $c['email'] ?? '',
                'website' => rtrim($this->baseUrl, '/'),
                'footer' => trim(($c['legal_name'] ?? '') . ' · ' . ($c['phone_display'] ?? '')),
            ],
        ];
    }

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
