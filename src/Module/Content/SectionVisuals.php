<?php

declare(strict_types=1);

namespace Mea\Module\Content;

/**
 * Standard-Inhalte der Icon-Fläche neben Textabschnitten ohne Karten (Campus-Seiten).
 * Angaben im Abschnitt selbst (icon, points) haben Vorrang. Die Stichpunkte stammen aus dem Abschnittstext.
 */
final class SectionVisuals
{
    /** @var array<string,array{icon:string,points:list<array{icon:string,title:string,text:string}>}> Schlüssel: kleine Zeile des Abschnitts */
    private const MAP = [
        'Lernform' => ['icon' => 'users', 'points' => [
            ['icon' => 'users', 'title' => 'Gruppe', 'text' => '10 bis 15 Teilnehmende'],
            ['icon' => 'user-check', 'title' => 'Einzelbegleitung', 'text' => 'Ihr Bedarf im Mittelpunkt'],
            ['icon' => 'handshake', 'title' => 'Beratung zuerst', 'text' => 'kein Format vorab nötig'],
        ]],
        'Regional erreichbar' => ['icon' => 'map-pin', 'points' => [
            ['icon' => 'map-pin', 'title' => 'Marl', 'text' => ''],
            ['icon' => 'map-pin', 'title' => 'Recklinghausen', 'text' => ''],
            ['icon' => 'map-pin', 'title' => 'Wanne-Eickel', 'text' => ''],
            ['icon' => 'map-pin', 'title' => 'Bochum', 'text' => ''],
        ]],
    ];

    /** @return array{icon:string,points:list<array{icon:string,title:string,text:string}>}|null */
    public static function get(string $eyebrow): ?array
    {
        return self::MAP[trim($eyebrow)] ?? null;
    }
}
