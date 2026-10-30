# Go-live-Checkliste und Rollbackplan

Ziel: Umschaltung von WordPress auf die neue Website ohne Datenverlust und mit kurzer Unterbrechung. WordPress bleibt dabei unverändert im alten Ordner liegen. Ein Rückweg besteht bis zum Abschluss aus einer einzigen Einstellung in Plesk.

`php bin/console golive:check` prüft alle automatisch prüfbaren Punkte. Die übrigen Punkte unten werden von Hand abgehakt.

## A. Vorbereitung (bis 1 Woche vorher)

| ✔ | Punkt | Wer | Hinweis |
|---|---|---|---|
| ☐ | Neue Website auf Vorschau-Subdomain installiert (`deployment.md`), `APP_NOINDEX=true` | Technik | |
| ☐ | Rechtstexte (Impressum inkl. USt-IdNr., Datenschutzerklärung, AGB/Teilnahmebedingungen) fachlich geprüft und in der Verwaltung eingesetzt | MEA + Fachperson | `golive:check` meldet Platzhalter |
| ☐ | Löschfristen in Einstellungen bestätigt | MEA + Steuerberatung | Voreinstellung 10 Jahre für bezahlte Buchungen |
| ☐ | Prüfungen mit echten Preisen, Gebühren und Terminen gepflegt und veröffentlicht | MEA | |
| ☐ | Inhalte aller Seiten gelesen und freigegeben | MEA | |
| ☐ | 3 PDF-Dateien von der alten Download-Seite in die Mediathek geladen und auf den AVGS-Seiten verlinkt | MEA | siehe Abschnitt E |
| ☐ | Bewertungen nur mit Einverständnis und echter Quelle eingetragen | MEA | keine erfundenen Bewertungen |
| ☐ | PayPal Sandbox: Testbuchung mit Zahlung, Abbruch und Erstattung durchgespielt | MEA + Technik | |
| ☐ | SMTP: Testversand an eigene Adresse angekommen (nicht im Spam) | Technik | |
| ☐ | Zwei Administratorkonten mit Zwei-Faktor-Anmeldung, Backup-Codes sicher verwahrt | MEA | |
| ☐ | Cronjob läuft (Systemprüfung „Cronjob“ grün) | Technik | |
| ☐ | Sicherung erstellt, heruntergeladen, testweise wiederhergestellt | Technik | |
| ☐ | `.env` (mit `APP_KEY`, `BACKUP_KEY`) getrennt aufbewahrt | MEA | |
| ☐ | Besucher-IP wird korrekt erkannt (Verwaltung → Sicherheit) | Technik | `deployment.md` Abschnitt 7 |
| ☐ | Externe Erreichbarkeitsüberwachung eingerichtet (z. B. Uptime-Dienst, prüft `/` alle 5 Minuten) | Technik | ergänzt die interne Systemprüfung |
| ☐ | Bedienung mit Tastatur und Screenreader stichprobenartig geprüft | Person mit Erfahrung | automatische Prüfung ist grün |
| ☐ | Zeitpunkt festgelegt: ruhige Zeit, kein Prüfungstermin am selben oder nächsten Tag | MEA | |

## B. Am Tag vorher

| ✔ | Punkt |
|---|---|
| ☐ | Im alten Shop neue Bestellungen stoppen (WooCommerce: Produkt auf „nicht vorrätig“ oder Katalogmodus). Auf der alten Seite einen Hinweis „Online-Anmeldung ab [Datum] auf der neuen Website“ setzen. |
| ☐ | Vollständige Plesk-Sicherung von WordPress (Dateien und Datenbank) erstellen und herunterladen. Diese Sicherung wird wegen der Aufbewahrungspflicht für alte Bestellungen archiviert (nicht löschen). |
| ☐ | `php bin/console golive:check` auf der Vorschau: nur noch die Punkte offen, die sich erst mit der Hauptdomain ändern (`APP_URL`, `APP_NOINDEX`, PayPal live). |

## C. Umschaltung (ca. 30 Minuten)

| Schritt | Aktion | Prüfung |
|---|---|---|
| 1 | Offene Bestellungen aus WooCommerce mit künftigen Terminen auflisten (Bestellnummer, Person, Termin, Betrag, bezahlt ja/nein). | Liste vollständig |
| 2 | Jede dieser Bestellungen in der neuen Verwaltung unter **Buchungen → Buchung übernehmen** eintragen (Referenz = WooCommerce-Bestellnummer, Bestätigungs-E-Mail nicht ankreuzen). | Anzahl übernommen = Anzahl offen; doppelte Referenzen werden abgelehnt |
| 3 | `.env` der neuen Website: `APP_URL=https://mea.ghazi-online.de`, `APP_NOINDEX=false`, `PAYPAL_MODE=live` mit Live-Zugang und Live-Webhook-ID. | |
| 4 | Sicherung in der Verwaltung erstellen („vor Livegang“) und herunterladen. | Sicherung „ok“ |
| 5 | In Plesk den **Dokumentenstamm der Hauptdomain** von `httpdocs` auf `mea-app/public` umstellen. HTTPS-Zertifikat der Hauptdomain prüfen. | Startseite zeigt neue Website |
| 6 | `php bin/console golive:check` | „Bereit für den Livegang“ |
| 7 | Stichproben im Browser (Handy und Rechner): Startseite, Prüfungsseite, Kontaktformular senden, alte Adressen `/hier-herunterladen/`, `/telc/`, `/product/telc-sprachpruefung-deutsch-a2-b1/` leiten weiter. | |
| 8 | Eine echte Buchung mit kleinem Betrag oder eigener Teilnahme durchführen und anschließend erstatten. | Bestätigung und E-Mail kommen an |
| 9 | Google Search Console: neue Sitemap `https://mea.ghazi-online.de/sitemap.xml` einreichen. | |

## D. Rollback (falls Schritt 6–8 scheitert)

Die Entscheidung trifft MEA. Bis 24 Stunden nach der Umschaltung gilt:

1. In Plesk den Dokumentenstamm der Hauptdomain wieder auf `httpdocs` (WordPress) stellen. Die alte Website ist sofort wieder da.
2. Im WooCommerce-Shop Bestellungen wieder zulassen.
3. Buchungen, die in der Zwischenzeit auf der neuen Website eingegangen sind: In der neuen Verwaltung unter **Buchungen** (Export als CSV) ansehen und die Personen direkt kontaktieren. Bezahlte Buchungen bleiben in PayPal sichtbar und können dort erstattet oder manuell im alten Shop erfasst werden.
4. Fehler beheben, neuen Termin festlegen, ab Abschnitt C wiederholen.

Nach erfolgreichem Livegang bleibt WordPress noch 4 Wochen im alten Ordner (ohne Domain, nicht öffentlich erreichbar). Danach den Ordner löschen; die archivierte Plesk-Sicherung bleibt erhalten.

## E. Nach dem Livegang

| Wann | Punkt |
|---|---|
| Tag 1 | Verwaltung → Systemprüfung, E-Mail-Protokoll, Sicherheit prüfen |
| Tag 1–14 | Verwaltung → **Weiterleitungen**: Liste „Nicht gefundene Adressen“ ansehen und für häufige alte Adressen Weiterleitungen anlegen (z. B. alte PDF-Adressen `/wp-content/uploads/2024/06/Ganzheitliche-Betreuung-nach-16k.pdf`, `/wp-content/uploads/2024/08/MEA-Plus-Berufsbezogene-Kommunikationstraing.pdf`, `/wp-content/uploads/2024/08/1.pdf` → neue PDF-Adresse aus der Mediathek) |
| Woche 1 | Google Search Console: Abdeckung und 404-Fehler prüfen |
| Woche 1 | Ladezeit auf dem echten Server messen (PageSpeed Insights) |
| Monatlich | Sicherung herunterladen und extern speichern (Erinnerung kommt per E-Mail) |
| Nach 4 Wochen | WordPress-Ordner entfernen, WordPress-Datenbank löschen (Sicherung bleibt archiviert) |

## Was bei der Umschaltung nicht verloren gehen darf

| Daten | Wie gesichert |
|---|---|
| Bezahlte, künftige Bestellungen | Bestellstopp am Vortag, Übernahme mit Referenz (Schritt 1–2), doppelte Übernahme technisch ausgeschlossen |
| Alte Bestellungen und Rechnungen | archivierte WordPress-Sicherung, Buchhaltung |
| Kontaktanfragen über die alte Website | vor der Umschaltung im bisherigen Postfach bzw. in WordPress abarbeiten; sie bleiben in der archivierten Sicherung |
| Suchmaschinen-Rang | 301-Weiterleitungen für alle 93 Adressen der alten Sitemap, neue Sitemap |
