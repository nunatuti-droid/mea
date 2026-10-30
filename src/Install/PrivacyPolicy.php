<?php
declare(strict_types=1);

namespace Mea\Install;

/**
 * Text der Datenschutzerklärung, abgestimmt auf die tatsächlich genutzten Funktionen der Website.
 * Muss vor dem Livegang von einer fachkundigen Person geprüft werden (siehe docs/aenderungen.md).
 */
final class PrivacyPolicy
{
    /** Ursprünglicher Platzhaltertext aus den Startdaten (nur dieser wird automatisch ersetzt). */
    public const SEEDED_HTML = '<p><strong>Hinweis:</strong> Die Datenschutzerklärung für die neue Website wird derzeit fachlich erstellt und geprüft. Bis dahin gilt die Datenschutzerklärung der bisherigen Website.</p>'
        . '<h2>Verantwortlicher</h2><p>MEA Bildungszentrum GmbH, Barkhausstraße 22, 45768 Marl. Vertreten durch Elmedina Mernica und Saudin Cizmic.</p>'
        . '<h2>Was diese Website technisch tut</h2><ul><li>Keine Tracking- oder Marketing-Cookies, keine Analysedienste.</li><li>Schriften und Bilder werden vom eigenen Server geladen.</li><li>Kartendienste (OpenStreetMap) werden erst nach Ihrem Klick geladen.</li><li>Ein technisch notwendiges Sitzungs-Cookie wird nur beim Absenden von Formularen gesetzt.</li></ul>';

    /** Abschnitt 13 in der ersten Fassung (vor dem Bewerbungsformular). */
    public const APPLICATIONS_HTML_V1 = '<p>Wenn Sie sich bei uns bewerben, verarbeiten wir Ihre Angaben und Unterlagen, um über die Zusammenarbeit zu entscheiden (Art. 6 Abs. 1 lit. b DSGVO). Kommt keine Zusammenarbeit zustande, löschen wir Ihre Unterlagen spätestens sechs Monate nach Abschluss des Verfahrens, sofern Sie nicht in eine längere Speicherung eingewilligt haben.</p>';

    public const APPLICATIONS_HTML = '<p>Über unser <a href="/karriere/bewerbung/">Bewerbungsformular</a> verarbeiten wir die Stelle, auf die Sie sich bewerben, Ihren Namen, Ihre E-Mail-Adresse, auf Wunsch Ihre Telefonnummer und Nachricht sowie die hochgeladenen Unterlagen (PDF). Wir nutzen diese Angaben nur, um über die Zusammenarbeit zu entscheiden (Art. 6 Abs. 1 lit. b DSGVO).</p>'
        . '<p>Die Unterlagen werden verschlüsselt auf unserem Server gespeichert und nicht per E-Mail weitergeleitet. Zugriff haben nur die Personen, die über Bewerbungen entscheiden.</p>'
        . '<p>Kommt keine Zusammenarbeit zustande, löschen wir Ihre Bewerbung samt Unterlagen sechs Monate nach Abschluss des Verfahrens, sofern Sie nicht in eine längere Speicherung eingewilligt haben. Bewerbungen, deren Verfahren nicht abgeschlossen wurde, löschen wir spätestens nach zwölf Monaten.</p>';

    public static function html(): string
    {
        return '<p>Stand: Oktober 2026</p>'
            . '<p>Mit diesen Hinweisen informieren wir Sie, welche personenbezogenen Daten wir beim Besuch unserer Website und bei der Nutzung unserer Angebote verarbeiten, wofür wir sie nutzen und welche Rechte Sie haben.</p>'

            . '<h2 id="verantwortlicher">1. Verantwortlicher</h2>'
            . '<p>MEA Bildungszentrum GmbH<br>Barkhausstraße 22<br>45768 Marl</p>'
            . '<p>Vertreten durch die Geschäftsführung: Saudin Cizmic und Elmedina Mernica</p>'
            . '<p>Telefon: +49 2365 6973491<br>Kontakt: über unser <a href="/kontakt/">Kontaktformular</a> oder per Post an die obige Anschrift</p>'

            . '<h2 id="hosting">2. Hosting und Server-Logdateien</h2>'
            . '<p>Unsere Website wird bei der netcup GmbH, Daimlerstraße 25, 76185 Karlsruhe, auf Servern in Deutschland betrieben. Beim Aufruf jeder Seite verarbeitet der Webserver technisch notwendige Angaben: IP-Adresse, Datum und Uhrzeit, aufgerufene Adresse, übertragene Datenmenge, Statusmeldung, Browser und Betriebssystem sowie die zuvor besuchte Seite (Referrer). Ohne diese Angaben kann die Website nicht ausgeliefert werden.</p>'
            . '<p>Zum Schutz vor Angriffen (zum Beispiel massenhafte Anmeldeversuche oder automatisierte Anfragen) speichert unsere Website bei auffälligen Zugriffen eine gekürzte IP-Adresse und einen nicht umkehrbaren Prüfwert dieser Adresse. Diese Einträge werden nach spätestens 90 Tagen gelöscht.</p>'
            . '<p>Rechtsgrundlage ist unser berechtigtes Interesse an einer sicheren und funktionsfähigen Website (Art. 6 Abs. 1 lit. f DSGVO).</p>'

            . '<h2 id="verschluesselung">3. Verschlüsselung</h2>'
            . '<p>Die Verbindung zu unserer Website ist per TLS verschlüsselt. Sie erkennen das an „https://“ und dem Schloss-Symbol in der Adresszeile Ihres Browsers.</p>'

            . '<h2 id="cookies">4. Cookies und Einwilligung</h2>'
            . '<p>Wir setzen nur Cookies, die für den Betrieb der Website nötig sind:</p>'
            . '<ul>'
            . '<li><strong>Sitzungs-Cookie</strong> („mea“): wird beim Aufruf des Kontakt- oder Anmeldeformulars gesetzt und schützt die Formulare vor Missbrauch. Es wird beim Schließen des Browsers gelöscht.</li>'
            . '<li><strong>Einwilligungs-Cookie</strong> („mea_consent“): speichert Ihre Auswahl im Cookie-Hinweis, damit wir Sie nicht bei jedem Besuch erneut fragen. Speicherdauer: 12 Monate.</li>'
            . '</ul>'
            . '<p>Rechtsgrundlage für diese notwendigen Cookies ist § 25 Abs. 2 Nr. 2 TDDDG in Verbindung mit Art. 6 Abs. 1 lit. f DSGVO.</p>'
            . '<p>Als Nachweis Ihrer Auswahl (Art. 7 Abs. 1 DSGVO) speichern wir eine zufällige Kennung, die gewählten Kategorien, die Version der Hinweise und den Zeitpunkt. Eine IP-Adresse speichern wir dabei nicht. Die Nachweise werden nach drei Jahren gelöscht.</p>'
            . '<p>Sie können Ihre Auswahl jederzeit über „Cookie-Einstellungen“ unten auf jeder Seite ändern oder widerrufen. Der Widerruf gilt für die Zukunft.</p>'

            . '<h2 id="schriften">5. Schriften und Inhalte vom eigenen Server</h2>'
            . '<p>Schriften, Bilder und Programmdateien werden von unserem eigenen Server geladen. Es werden dafür keine Daten an Dritte übertragen.</p>'

            . '<h2 id="kontakt">6. Kontaktformular, E-Mail und Telefon</h2>'
            . '<p>Wenn Sie uns über das Kontaktformular schreiben, verarbeiten wir Ihren Namen, Ihre E-Mail-Adresse, auf Wunsch Ihre Telefonnummer, das gewählte Thema und den Standort sowie Ihre Nachricht. Bei Kontakt per E-Mail, Telefon oder WhatsApp verarbeiten wir die Angaben, die Sie uns dabei mitteilen.</p>'
            . '<p>Wir nutzen die Angaben nur, um Ihre Anfrage zu beantworten. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO, wenn Ihre Anfrage mit einem Vertrag oder dessen Anbahnung zusammenhängt, sonst unser berechtigtes Interesse an der Beantwortung von Anfragen (Art. 6 Abs. 1 lit. f DSGVO).</p>'
            . '<p>Anfragen über das Kontaktformular werden 12 Monate nach Eingang automatisch anonymisiert, sofern keine gesetzlichen Aufbewahrungspflichten entgegenstehen.</p>'

            . '<h2 id="pruefungsanmeldung">7. Anmeldung zu telc-Prüfungen</h2>'
            . '<p>Für die Anmeldung zu einer Prüfung verarbeiten wir die im Formular abgefragten Angaben, zum Beispiel Name, Geburtsdatum und Geburtsort, Anschrift, E-Mail-Adresse und Telefonnummer, sowie den gewählten Prüfungstermin.</p>'
            . '<p>Wir nutzen diese Angaben, um Ihre Anmeldung zu bearbeiten, Ihren Platz zu reservieren, die Zahlung abzuwickeln, Sie über den Termin zu informieren und die Prüfung durchzuführen. Für die Durchführung der Prüfung und die Ausstellung des Zertifikats übermitteln wir die dafür nötigen Angaben an den Prüfungsanbieter telc gGmbH, Frankfurt am Main.</p>'
            . '<p>Rechtsgrundlage ist die Erfüllung des Vertrags (Art. 6 Abs. 1 lit. b DSGVO) und unsere gesetzlichen Aufbewahrungspflichten (Art. 6 Abs. 1 lit. c DSGVO).</p>'
            . '<p>Speicherdauer: Abgebrochene oder nicht bezahlte Anmeldungen werden nach 30 Tagen anonymisiert. Angaben zu bezahlten Anmeldungen bewahren wir für die Dauer der handels- und steuerrechtlichen Aufbewahrungsfristen auf (bis zu zehn Jahre ab Ende des Prüfungsjahres) und anonymisieren sie danach.</p>'

            . '<h2 id="zahlung">8. Zahlung mit PayPal</h2>'
            . '<p>Für die Zahlung der Prüfungsgebühr leiten wir Sie zu PayPal weiter. Anbieter ist die PayPal (Europe) S.à r.l. et Cie, S.C.A., 22-24 Boulevard Royal, L-2449 Luxemburg. PayPal verarbeitet Ihre Zahlungsdaten in eigener Verantwortung. Wir erhalten von PayPal nur die Angaben, die wir für die Zuordnung der Zahlung brauchen, zum Beispiel Transaktionsnummer, Betrag und Zahlungsstatus.</p>'
            . '<p>Rechtsgrundlage ist die Erfüllung des Vertrags (Art. 6 Abs. 1 lit. b DSGVO). Weitere Informationen finden Sie in der <a href="https://www.paypal.com/de/legalhub/privacy-full">Datenschutzerklärung von PayPal</a>.</p>'

            . '<h2 id="e-mails">9. E-Mails zu Ihrer Anmeldung</h2>'
            . '<p>Wir senden Ihnen E-Mails zu Ihrer Anfrage oder Anmeldung, zum Beispiel eine Bestätigung und eine Erinnerung vor dem Prüfungstermin. Die E-Mails werden über den Mailserver unseres Hosters versendet. Eine Kopie des Versandprotokolls wird nach 180 Tagen gelöscht.</p>'

            . '<h2 id="karten">10. Karten von Google Maps</h2>'
            . '<p>Auf unseren Standortseiten können Sie eine Karte von Google Maps anzeigen. Anbieter ist die Google Ireland Limited, Gordon House, Barrow Street, Dublin 4, Irland. Die Karte wird erst geladen, wenn Sie auf „Karte laden“ klicken oder im Cookie-Hinweis „Externe Medien“ erlaubt haben. Vorher werden keine Daten an Google übertragen.</p>'
            . '<p>Sobald die Karte geladen ist, erhält Google unter anderem Ihre IP-Adresse und Angaben zu Ihrem Browser und kann Cookies setzen. Dabei kann es zu einer Übermittlung in die USA kommen. Google ist nach dem EU-US Data Privacy Framework zertifiziert.</p>'
            . '<p>Rechtsgrundlage ist Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO, § 25 Abs. 1 TDDDG). Sie können sie jederzeit über „Cookie-Einstellungen“ widerrufen. Weitere Informationen finden Sie in der <a href="https://policies.google.com/privacy?hl=de">Datenschutzerklärung von Google</a>.</p>'
            . '<p>Die Links „Route planen“ und „Bewertungen auf Google ansehen“ sind einfache Links. Daten werden erst übertragen, wenn Sie einen dieser Links anklicken und damit die Seite von Google öffnen.</p>'

            . '<h2 id="links">11. Links zu sozialen Netzwerken und WhatsApp</h2>'
            . '<p>Unsere Website enthält Links zu WhatsApp, Facebook und Instagram. Es handelt sich um einfache Links, nicht um eingebundene Plugins. Beim Aufruf unserer Website werden keine Daten an diese Anbieter übertragen. Erst wenn Sie einen Link anklicken, gelangen Sie zum jeweiligen Anbieter, für den dann dessen Datenschutzbestimmungen gelten.</p>'

            . '<h2 id="analyse">12. Analyse- und Marketingdienste</h2>'
            . '<p>Derzeit setzen wir keine Analyse- oder Marketingdienste ein und binden keine Videos von externen Anbietern ein. Sollten wir solche Dienste künftig nutzen, laden wir sie nur mit Ihrer Einwilligung und ergänzen diese Datenschutzerklärung vorher.</p>'

            . '<h2 id="bewerbungen">13. Bewerbungen</h2>'
            . self::APPLICATIONS_HTML

            . '<h2 id="empfaenger">14. Empfänger und Übermittlung in Drittländer</h2>'
            . '<p>Innerhalb unseres Unternehmens erhalten nur die Personen Zugriff, die die Daten für ihre Aufgaben brauchen. Externe Empfänger sind nur die oben genannten Dienstleister und Stellen. Eine Übermittlung in Länder außerhalb der EU findet nur bei Google Maps (nach Ihrer Einwilligung) statt, wie in Abschnitt 10 beschrieben.</p>'

            . '<h2 id="rechte">15. Ihre Rechte</h2>'
            . '<p>Sie haben das Recht auf:</p>'
            . '<ul>'
            . '<li>Auskunft über Ihre gespeicherten Daten (Art. 15 DSGVO)</li>'
            . '<li>Berichtigung unrichtiger Daten (Art. 16 DSGVO)</li>'
            . '<li>Löschung (Art. 17 DSGVO)</li>'
            . '<li>Einschränkung der Verarbeitung (Art. 18 DSGVO)</li>'
            . '<li>Datenübertragbarkeit (Art. 20 DSGVO)</li>'
            . '<li>Widerruf einer erteilten Einwilligung mit Wirkung für die Zukunft (Art. 7 Abs. 3 DSGVO)</li>'
            . '</ul>'
            . '<p><strong>Widerspruchsrecht:</strong> Verarbeiten wir Daten auf Grundlage unseres berechtigten Interesses (Art. 6 Abs. 1 lit. f DSGVO), können Sie aus Gründen, die sich aus Ihrer besonderen Situation ergeben, jederzeit widersprechen (Art. 21 DSGVO).</p>'
            . '<p>Für Ihre Anfragen nutzen Sie bitte die Kontaktangaben in Abschnitt 1.</p>'

            . '<h2 id="beschwerde">16. Beschwerderecht</h2>'
            . '<p>Sie können sich bei einer Datenschutz-Aufsichtsbehörde beschweren. Für uns zuständig ist die Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen, Kavalleriestraße 2–4, 40213 Düsseldorf, <a href="https://www.ldi.nrw.de">www.ldi.nrw.de</a>.</p>'

            . '<h2 id="pflicht">17. Pflicht zur Bereitstellung</h2>'
            . '<p>Sie müssen uns keine Daten geben. Ohne die im Anmeldeformular als Pflichtfeld markierten Angaben können wir Sie jedoch nicht zur Prüfung anmelden, und ohne Kontaktangaben können wir Ihre Anfrage nicht beantworten. Eine automatisierte Entscheidungsfindung oder ein Profiling findet nicht statt.</p>'

            . '<h2 id="aenderungen">18. Änderungen</h2>'
            . '<p>Wir passen diese Datenschutzerklärung an, wenn sich unsere Website oder die rechtlichen Vorgaben ändern. Es gilt die jeweils hier veröffentlichte Fassung.</p>';
    }
}
