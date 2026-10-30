<?php
declare(strict_types=1);

namespace Mea\Module\Auth;

/** Feste Rollen; die Rechte werden bei jeder Anfrage serverseitig geprüft. */
final class Roles
{
    public const LABELS = [
        'admin' => 'Administrator',
        'editor' => 'Redaktion',
        'booking' => 'Buchungsverwaltung',
    ];

    public const PERMISSIONS = [
        'dashboard' => 'Übersicht',
        'content' => 'Seiten, Leistungen, FAQ, Bewertungen, Menüs',
        'media' => 'Mediathek',
        'locations' => 'Standorte',
        'exams' => 'Prüfungsarten, Anbieter, Buchungsregeln',
        'sessions' => 'Termine und Kalender',
        'bookings' => 'Buchungen',
        'refunds' => 'Erstattungen',
        'inquiries' => 'Anfragen',
        'emails' => 'E-Mail-Vorlagen und Protokoll',
        'settings' => 'Firmendaten, SEO, Datenschutzfristen',
        'users' => 'Benutzer und Rollen',
        'audit' => 'Änderungsprotokoll',
        'system' => 'Systemprüfung',
    ];

    private const GRANTS = [
        'admin' => ['*'],
        'editor' => ['dashboard', 'content', 'media', 'locations', 'exams', 'inquiries'],
        'booking' => ['dashboard', 'sessions', 'bookings', 'refunds', 'inquiries', 'emails'],
    ];

    public static function can(string $role, string $permission): bool
    {
        $grants = self::GRANTS[$role] ?? [];
        return in_array('*', $grants, true) || in_array($permission, $grants, true);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? $role;
    }

    public static function valid(string $role): bool
    {
        return isset(self::LABELS[$role]);
    }
}
