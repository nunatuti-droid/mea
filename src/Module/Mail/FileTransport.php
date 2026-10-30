<?php
declare(strict_types=1);

namespace Mea\Module\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** Nur für Entwicklung: schreibt Nachrichten als .eml-Datei statt sie zu versenden (MAIL_DSN=file). */
final class FileTransport implements TransportInterface
{
    public function __construct(private string $dir) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        @mkdir($this->dir, 0750, true);
        $envelope ??= Envelope::create($message);
        file_put_contents($this->dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml', $message->toString());
        return new SentMessage($message, $envelope);
    }

    public function __toString(): string
    {
        return 'file://' . $this->dir;
    }
}
