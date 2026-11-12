<?php

declare(strict_types=1);

namespace Mea\Module\Content;

/**
 * Standard-Sprüche für die Seitenköpfe (grüne Zeile unter der Überschrift).
 * Ein Spruch im Seitenbaukasten (Feld „Spruch“) bzw. in den Angaben der Leistung hat Vorrang.
 * Rechtstexte haben bewusst keinen Spruch.
 */
final class HeroSlogans
{
    /** @var array<string,string> Schlüssel: Seiten-Adresse oder „übergeordnet/leistung“ */
    private const MAP = [
        // Seiten
        'leistungen' => 'Sprache. Beruf. Zukunft.',
        'telc-pruefungszentrum' => 'Ihre Prüfung. Unser Zentrum.',
        'preise' => 'Gebühren. Transparent.',
        'standorte' => 'Nah bei Ihnen. Im Ruhrgebiet.',
        'kontakt' => 'Wir hören zu. Wir melden uns.',
        'faq' => 'Antworten. Schnell gefunden.',
        'ueber-uns' => 'Menschen. Bildung. Perspektive.',
        'karriere' => 'Gemeinsam wachsen.',
        'bewerbung' => 'Ihre Unterlagen. Sicher übermittelt.',
        'standort' => 'Ihr Weg zu uns.',
        'anmeldung' => 'Ein Schritt bis zum Termin.',
        // Campus
        'bildungszentrum' => 'Lernen. Ankommen. Weiterkommen.',
        'bildungszentrum/ausbildung' => 'Orientierung. Grundlagen. Start.',
        'bildungszentrum/sprachtraining' => 'Sprache, die im Beruf trägt.',
        'bildungszentrum/pruefungen' => 'Vorbereiten. Prüfen. Weiterkommen.',
        'pruefungsvorbereitung' => 'Gezielt auf den Termin hinarbeiten.',
        'recruiting' => 'Fachkräfte finden. Zukunft sichern.',
        'recruiting/unternehmen' => 'Passende Fachkräfte. Persönlich begleitet.',
        'coaching' => 'Stabil im Beruf. Klar im nächsten Schritt.',
        'coaching/foerderung' => 'Finanzierung. Klar erklärt.',
        'coaching/teilnehmende' => 'Ihr Ziel. Ihr Tempo.',
        'coaching/jobcenter' => 'Zuweisung. Nachweise. Klarheit.',
        'sprache-beruf' => 'Verstanden werden. Im Beruf.',
        'sprache-beruf/berufsdeutsch' => 'Fachwörter. Gespräche. Sicherheit.',
        'sprache-beruf/unternehmen' => 'Gemeinsam sprechen. Besser zusammenarbeiten.',
    ];

    public static function get(string $key): string
    {
        return self::MAP[trim($key, '/')] ?? '';
    }
}
