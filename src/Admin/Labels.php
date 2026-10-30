<?php
declare(strict_types=1);

namespace Mea\Admin;

/** Anzeigenamen und Farben für Statuswerte in der Verwaltung. */
final class Labels
{
    public const MAPS = [
        'exam_status' => [
            'draft' => ['label' => 'Entwurf', 'tone' => 'neutral'],
            'published' => ['label' => 'Veröffentlicht', 'tone' => 'success'],
            'disabled' => ['label' => 'Deaktiviert', 'tone' => 'warning'],
            'archived' => ['label' => 'Archiviert', 'tone' => 'muted'],
        ],
        'content_status' => [
            'draft' => ['label' => 'Entwurf', 'tone' => 'neutral'],
            'published' => ['label' => 'Veröffentlicht', 'tone' => 'success'],
            'archived' => ['label' => 'Archiviert', 'tone' => 'muted'],
        ],
        'session_status' => [
            'open' => ['label' => 'Offen', 'tone' => 'success'],
            'closed' => ['label' => 'Anmeldung geschlossen', 'tone' => 'warning'],
            'cancelled' => ['label' => 'Abgesagt', 'tone' => 'danger'],
            'done' => ['label' => 'Durchgeführt', 'tone' => 'muted'],
        ],
        'booking_status' => [
            'reserved' => ['label' => 'Reserviert', 'tone' => 'neutral'],
            'pending_payment' => ['label' => 'Zahlung offen', 'tone' => 'warning'],
            'confirmed' => ['label' => 'Bestätigt', 'tone' => 'success'],
            'cancelled' => ['label' => 'Storniert', 'tone' => 'danger'],
            'expired' => ['label' => 'Abgelaufen', 'tone' => 'muted'],
            'refunded' => ['label' => 'Erstattet', 'tone' => 'muted'],
        ],
        'payment_status' => [
            'none' => ['label' => 'Keine Zahlung', 'tone' => 'muted'],
            'pending' => ['label' => 'Ausstehend', 'tone' => 'warning'],
            'paid' => ['label' => 'Bezahlt', 'tone' => 'success'],
            'failed' => ['label' => 'Fehlgeschlagen', 'tone' => 'danger'],
            'refunded' => ['label' => 'Erstattet', 'tone' => 'muted'],
            'partially_refunded' => ['label' => 'Teilweise erstattet', 'tone' => 'warning'],
        ],
        'inquiry_status' => [
            'new' => ['label' => 'Neu', 'tone' => 'warning'],
            'in_progress' => ['label' => 'In Bearbeitung', 'tone' => 'neutral'],
            'done' => ['label' => 'Erledigt', 'tone' => 'success'],
            'spam' => ['label' => 'Spam', 'tone' => 'muted'],
        ],
        'user_status' => [
            'invited' => ['label' => 'Eingeladen', 'tone' => 'neutral'],
            'active' => ['label' => 'Aktiv', 'tone' => 'success'],
            'disabled' => ['label' => 'Gesperrt', 'tone' => 'danger'],
        ],
        'email_status' => [
            'queued' => ['label' => 'Wartet', 'tone' => 'neutral'],
            'sending' => ['label' => 'Wird gesendet', 'tone' => 'neutral'],
            'sent' => ['label' => 'Gesendet', 'tone' => 'success'],
            'failed' => ['label' => 'Fehlgeschlagen', 'tone' => 'danger'],
        ],
        'bool' => [
            '1' => ['label' => 'Ja', 'tone' => 'success'],
            '0' => ['label' => 'Nein', 'tone' => 'muted'],
        ],
    ];

    /** @return array<string,string> */
    public static function options(string $map): array
    {
        return array_map(fn($s) => $s['label'], self::MAPS[$map]);
    }
}
