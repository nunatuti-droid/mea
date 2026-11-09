<?php
declare(strict_types=1);

namespace Mea\Module\Content;

/**
 * Texte und Links der Bereiche, die auf allen Seiten erscheinen (Kopfbereich, Fußbereich, Schnellleiste).
 * Gespeichert in der Einstellung „texts“; leere Werte nutzen den Standardtext. Ausgabe in Templates mit txt('schlüssel').
 */
final class TemplateTexts
{
    public const SETTING = 'texts';

    /** @return array<string,array{group:string,label:string,default:string,type:string,max:int}> */
    public static function catalog(): array
    {
        $t = fn(string $group, string $label, string $default, string $type = 'text', int $max = 120) => compact('group', 'label', 'default', 'type', 'max');
        return [
            'header.cta_label' => $t('Kopfbereich', 'Button oben rechts: Beschriftung', 'Prüfung buchen', 'text', 40),
            'header.cta_url' => $t('Kopfbereich', 'Button oben rechts: Ziel', '/telc-pruefungszentrum/', 'url', 300),
            'footer.about' => $t('Fußbereich', 'Text unter dem Logo (nach dem Leitsatz aus den Firmendaten)', 'Wir begleiten Menschen und Unternehmen auf dem Weg in Arbeit, Sprache und Beruf.', 'textarea', 400),
            'footer.contact_label' => $t('Fußbereich', 'Button unter dem Logo: Beschriftung', 'E-Mail schreiben', 'text', 40),
            'footer.contact_url' => $t('Fußbereich', 'Button unter dem Logo: Ziel', '/kontakt/#formular', 'url', 300),
            'footer.company_heading' => $t('Fußbereich', 'Überschrift der Spalte mit dem Fußmenü', 'Unternehmen', 'text', 60),
            'footer.locations_heading' => $t('Fußbereich', 'Überschrift der Spalte mit den Standorten', 'Standorte', 'text', 60),
            'footer.google_heading' => $t('Fußbereich', 'Überschrift über den Google-Bewertungen', 'Das sagen unsere Teilnehmenden auf Google', 'text', 120),
            'footer.voice_heading' => $t('Fußbereich', 'Überschrift über der Kundenstimme', 'Stimmen unserer Teilnehmenden', 'text', 120),
            'footer.voice_link' => $t('Fußbereich', 'Link zu allen Stimmen ({n} = Anzahl)', 'Alle {n} Stimmen lesen', 'text', 80),
            'footer.voice_url' => $t('Fußbereich', 'Link zu allen Stimmen: Ziel', '/ueber-uns/#stimmen', 'url', 300),
            'footer.consent_label' => $t('Fußbereich', 'Link zu den Cookie-Einstellungen', 'Cookie-Einstellungen', 'text', 60),
            'quickbar.call' => $t('Schnellleiste (Handy, unten)', 'Anrufen', 'Anrufen', 'text', 30),
            'quickbar.contact' => $t('Schnellleiste (Handy, unten)', 'Kontakt (wenn kein WhatsApp eingetragen ist)', 'Kontakt', 'text', 30),
            'quickbar.cta_label' => $t('Schnellleiste (Handy, unten)', 'Hauptbutton: Beschriftung', 'Prüfung buchen', 'text', 30),
            'quickbar.cta_url' => $t('Schnellleiste (Handy, unten)', 'Hauptbutton: Ziel', '/telc-pruefungszentrum/', 'url', 300),
        ];
    }

    /** @param array<string,mixed> $stored */
    public static function get(array $stored, string $key, array $vars = []): string
    {
        $def = self::catalog()[$key] ?? null;
        if ($def === null) {
            return '';
        }
        $v = is_string($stored[$key] ?? null) && trim($stored[$key]) !== '' ? $stored[$key] : $def['default'];
        return strtr($v, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), array_map('strval', array_values($vars))) ?: []);
    }
}
