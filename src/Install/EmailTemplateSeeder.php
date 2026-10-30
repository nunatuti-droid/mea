<?php
declare(strict_types=1);

namespace Mea\Install;

use Mea\Core\Database;

/**
 * Legt die Standard-E-Mail-Vorlagen an. Vorhandene Vorlagen bleiben unverändert,
 * damit Anpassungen aus der Verwaltung nicht überschrieben werden.
 */
final class EmailTemplateSeeder
{
    private const BOOKING_VARS = ['teilnehmer.vorname', 'teilnehmer.nachname', 'teilnehmer.name', 'buchung.nummer', 'buchung.betrag',
        'pruefung.name', 'pruefung.niveau', 'termin.datum', 'termin.uhrzeit', 'termin.ort', 'termin.hinweise', 'firma.name', 'firma.telefon', 'firma.email', 'firma.website'];

    public function __construct(private Database $db) {}

    public function run(): int
    {
        $n = 0;
        foreach (self::templates() as $key => $t) {
            $n += $this->db->run(
                'INSERT IGNORE INTO email_templates (template_key, name, description, subject, body_html, placeholders_json) VALUES (?, ?, ?, ?, ?, ?)',
                [$key, $t['name'], $t['description'], $t['subject'], $t['body'], json_encode($t['vars'], JSON_UNESCAPED_UNICODE)],
            );
        }
        return $n;
    }

    /** @return array<string, array{name:string, description:string, subject:string, body:string, vars:list<string>}> */
    public static function templates(): array
    {
        $contact = '<p>Bei Fragen erreichen Sie uns unter {{ firma.telefon }} oder per E-Mail an {{ firma.email }}.</p>'
            . '<p>Viele Grüße<br>{{ firma.name }}</p>';
        // Angaben als Tabelle: der Mail-Renderer gestaltet sie als Kasten (auch nach Bearbeitung im Editor)
        $exam = '<table><tr><th>Prüfung</th><td>{{ pruefung.name }}</td></tr><tr><th>Datum</th><td>{{ termin.datum }}, {{ termin.uhrzeit }} Uhr</td></tr>'
            . '<tr><th>Ort</th><td>{{ termin.ort }}</td></tr><tr><th>Buchungsnummer</th><td>{{ buchung.nummer }}</td></tr></table>';
        return [
            'booking_confirmation' => [
                'name' => 'Buchungsbestätigung',
                'description' => 'Geht an Teilnehmende, sobald eine Buchung bestätigt ist.',
                'subject' => 'Ihre Anmeldung: {{ pruefung.name }} am {{ termin.datum }}',
                'body' => '<p>Guten Tag {{ teilnehmer.name }},</p><p>vielen Dank für Ihre Anmeldung. Ihr Platz ist verbindlich gebucht.</p>' . $exam
                    . '<table><tr><th>Preis</th><td>{% for p in preis.positionen %}{{ p.bezeichnung }}: {{ p.betrag }}<br>{% endfor %}</td></tr><tr><th>Gesamt</th><td>{{ buchung.betrag }}</td></tr></table>'
                    . '{% if termin.hinweise %}<p><strong>Hinweise zum Termin:</strong><br>{{ termin.hinweise|nl2br }}</p>{% endif %}'
                    . '<p>Bitte bringen Sie am Prüfungstag einen gültigen Lichtbildausweis mit.</p>' . $contact,
                'vars' => array_merge(self::BOOKING_VARS, ['preis.positionen']),
            ],
            'office_new_booking' => [
                'name' => 'Neue Buchung (Büro)',
                'description' => 'Interne Benachrichtigung an das Büro bei jeder bestätigten Buchung.',
                'subject' => 'Neue Buchung {{ buchung.nummer }}: {{ pruefung.name }} am {{ termin.datum }}',
                'body' => '<p>Es ist eine neue Buchung eingegangen.</p>' . $exam
                    . '<table><tr><th>Teilnehmer/in</th><td>{{ teilnehmer.name }}<br>{{ teilnehmer.email }}</td></tr><tr><th>Betrag</th><td>{{ buchung.betrag }}</td></tr></table>'
                    . '<p>Details stehen in der Verwaltung unter Buchungen.</p>',
                'vars' => array_merge(self::BOOKING_VARS, ['teilnehmer.email']),
            ],
            'booking_cancelled' => [
                'name' => 'Buchung storniert',
                'description' => 'Geht an Teilnehmende, wenn eine Buchung storniert wurde.',
                'subject' => 'Stornierung Ihrer Buchung {{ buchung.nummer }}',
                'body' => '<p>Guten Tag {{ teilnehmer.name }},</p><p>Ihre Buchung wurde storniert.</p>' . $exam
                    . '{% if hinweis %}<p>{{ hinweis|nl2br }}</p>{% endif %}' . $contact,
                'vars' => array_merge(self::BOOKING_VARS, ['hinweis']),
            ],
            'booking_refunded' => [
                'name' => 'Erstattung',
                'description' => 'Geht an Teilnehmende, wenn ein Betrag erstattet wurde.',
                'subject' => 'Erstattung zu Ihrer Buchung {{ buchung.nummer }}',
                'body' => '<p>Guten Tag {{ teilnehmer.name }},</p><p>zu Ihrer Buchung wurde eine Erstattung veranlasst.</p>' . $exam
                    . '{% if hinweis %}<p>{{ hinweis|nl2br }}</p>{% endif %}<p>Die Gutschrift erfolgt über PayPal. Je nach Zahlungsart kann es einige Tage dauern, bis sie sichtbar ist.</p>' . $contact,
                'vars' => array_merge(self::BOOKING_VARS, ['hinweis']),
            ],
            'session_changed' => [
                'name' => 'Termin geändert',
                'description' => 'Geht an alle bestätigten Teilnehmenden, wenn ein Termin geändert wird und die Benachrichtigung gewählt ist.',
                'subject' => 'Änderung Ihres Prüfungstermins: {{ pruefung.name }}',
                'body' => '<p>Guten Tag {{ teilnehmer.name }},</p><p>an Ihrem Prüfungstermin hat sich etwas geändert. Die aktuellen Angaben:</p>' . $exam
                    . '{% if hinweis %}<p><strong>Was sich geändert hat:</strong><br>{{ hinweis|nl2br }}</p>{% endif %}' . $contact,
                'vars' => array_merge(self::BOOKING_VARS, ['hinweis']),
            ],
            'session_cancelled' => [
                'name' => 'Termin abgesagt',
                'description' => 'Geht an alle bestätigten Teilnehmenden, wenn ein Termin abgesagt wird.',
                'subject' => 'Absage: {{ pruefung.name }} am {{ termin.datum }}',
                'body' => '<p>Guten Tag {{ teilnehmer.name }},</p><p>leider müssen wir den folgenden Prüfungstermin absagen:</p>' . $exam
                    . '{% if hinweis %}<p><strong>Grund:</strong> {{ hinweis|nl2br }}</p>{% endif %}'
                    . '{% if bezahlt %}<p>Wir melden uns bei Ihnen wegen eines Ersatztermins oder einer Erstattung.</p>{% endif %}' . $contact,
                'vars' => array_merge(self::BOOKING_VARS, ['hinweis', 'bezahlt']),
            ],
            'user_invite' => [
                'name' => 'Einladung Verwaltung',
                'description' => 'Einladung für neue Benutzer der Verwaltung.',
                'subject' => 'Einladung zur Verwaltung von {{ firma.name }}',
                'body' => '<p>Guten Tag {{ name }},</p><p>Sie wurden als {{ rolle }} zur Verwaltung der Website eingeladen.</p>'
                    . '<p>Bitte legen Sie über diesen Link Ihr Passwort fest und richten Sie die Zwei-Faktor-Anmeldung ein:</p><p>{{ link|raw_link(\'Einladung annehmen\') }}</p>'
                    . '<p>Der Link ist {{ gueltig_stunden }} Stunden gültig und kann nur einmal verwendet werden. Wenn Sie keine Einladung erwartet haben, ignorieren Sie diese E-Mail.</p>',
                'vars' => ['name', 'rolle', 'link', 'gueltig_stunden', 'firma.name'],
            ],
            'password_reset' => [
                'name' => 'Passwort zurücksetzen',
                'description' => 'Link zum Festlegen eines neuen Passworts.',
                'subject' => 'Neues Passwort festlegen',
                'body' => '<p>Guten Tag {{ name }},</p><p>für Ihr Konto wurde ein neues Passwort angefordert. Über diesen Link legen Sie es fest:</p><p>{{ link|raw_link(\'Neues Passwort festlegen\') }}</p>'
                    . '<p>Der Link ist {{ gueltig_minuten }} Minuten gültig. Wenn Sie das nicht angefordert haben, ignorieren Sie diese E-Mail. Ihr Passwort bleibt dann unverändert.</p>',
                'vars' => ['name', 'link', 'gueltig_minuten', 'firma.name'],
            ],
            'security_notice' => [
                'name' => 'Sicherheitshinweis',
                'description' => 'Hinweis bei sicherheitsrelevanten Änderungen am Konto (neues Gerät, Passwort, Sperre).',
                'subject' => 'Sicherheitshinweis zu Ihrem Konto',
                'body' => '<p>Guten Tag {{ name }},</p><p>{{ hinweis|nl2br }}</p><p>Wenn Sie das nicht selbst waren, ändern Sie bitte sofort Ihr Passwort und informieren Sie die Administration.</p>',
                'vars' => ['name', 'hinweis', 'firma.name'],
            ],
            'session_reminder' => [
                'name' => 'Terminerinnerung',
                'description' => 'Geht einige Tage vor der Prüfung an alle bestätigten Teilnehmenden (Anzahl der Tage unter Firmendaten einstellbar).',
                'subject' => 'Erinnerung: {{ pruefung.name }} am {{ termin.datum }}',
                'body' => '<p>Guten Tag {{ teilnehmer.name }},</p><p>wir erinnern Sie an Ihre Prüfung:</p>' . $exam
                    . '{% if termin.hinweise %}<p><strong>Hinweise zum Termin:</strong><br>{{ termin.hinweise|nl2br }}</p>{% endif %}'
                    . '<p>Bitte kommen Sie rechtzeitig und bringen Sie einen gültigen Lichtbildausweis mit.</p>' . $contact,
                'vars' => self::BOOKING_VARS,
            ],
            'system_alert' => [
                'name' => 'Systemmeldung (Administratoren)',
                'description' => 'Automatische Meldung an Administratoren, z. B. bei fehlgeschlagener Sicherung oder veränderten Programmdateien.',
                'subject' => 'Website-Hinweis: {{ titel }}',
                'body' => '<p>Guten Tag {{ name }},</p><p>{{ text }}</p>{% if details %}<p><strong>Details:</strong><br>{{ details|nl2br }}</p>{% endif %}'
                    . '<p>Diese Nachricht wurde automatisch von der Website erzeugt.</p>',
                'vars' => ['name', 'titel', 'text', 'details'],
            ],
            'inquiry_notification' => [
                'name' => 'Neue Kontaktanfrage (Büro)',
                'description' => 'Interne Benachrichtigung an das Büro bei einer neuen Anfrage über das Kontaktformular.',
                'subject' => 'Neue Anfrage: {{ anfrage.thema }}',
                'body' => '<p>Über das Kontaktformular ist eine neue Anfrage eingegangen.</p>'
                    . '<table><tr><th>Name</th><td>{{ anfrage.name }}</td></tr><tr><th>E-Mail</th><td>{{ anfrage.email }}</td></tr><tr><th>Telefon</th><td>{{ anfrage.telefon|default(\'–\') }}</td></tr>'
                    . '<tr><th>Thema</th><td>{{ anfrage.thema }}</td></tr><tr><th>Standort</th><td>{{ anfrage.standort|default(\'–\') }}</td></tr></table>'
                    . '<p><strong>Nachricht:</strong><br>{{ anfrage.nachricht|nl2br }}</p><p>Die Anfrage steht auch in der Verwaltung unter Anfragen.</p>',
                'vars' => ['anfrage.name', 'anfrage.email', 'anfrage.telefon', 'anfrage.thema', 'anfrage.standort', 'anfrage.nachricht'],
            ],
        ];
    }
}
