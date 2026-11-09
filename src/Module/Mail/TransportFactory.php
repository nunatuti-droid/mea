<?php
declare(strict_types=1);

namespace Mea\Module\Mail;

use Mea\Core\Config;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class TransportFactory
{
    /** MAIL_DSN: smtp://benutzer:passwort@host:587 (Produktion) oder "file" (nur Entwicklung). Leer = kein Versand. */
    public static function fromConfig(Config $c, string $root): ?TransportInterface
    {
        $dsn = $c->get('MAIL_DSN');
        if ($dsn === null) {
            return null;
        }
        if ($dsn === 'file') {
            if ($c->get('APP_ENV') === 'production') {
                return null;
            }
            return new FileTransport($root . '/storage/mail');
        }
        return Transport::fromDsn($dsn);
    }
}
