<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Core\Database;
use Mea\Module\Mail\Mailer;

/** Systemmeldungen an alle aktiven Administratoren (über die E-Mail-Warteschlange). */
final class Notifier
{
    public function __construct(private Database $db, private Mailer $mailer) {}

    /** @param string $key Gleicher Schlüssel = keine doppelte Mail */
    public function alert(string $title, string $text, string $key, string $details = ''): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT id, email, name FROM users WHERE role = 'admin' AND status = 'active'") as $u) {
            try {
                if ($this->mailer->queue('system_alert', (string) $u['email'], (string) $u['name'],
                    ['name' => $u['name'], 'titel' => $title, 'text' => $text, 'details' => $details], 'alert-' . $key . '-' . $u['id'], 'system', null)) {
                    $n++;
                }
            } catch (\Throwable) {
                // Vorlage fehlt o. Ä.: Meldung steht trotzdem im Protokoll und in der Systemprüfung
            }
        }
        return $n;
    }
}
