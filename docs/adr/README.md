# Architekturentscheidungen (ADR)

Festgelegt in Phase 2. Spalte „Stand“ zeigt, was bis Phase 5 umgesetzt ist.

| Nr. | Entscheidung | Grund | Verworfen | Stand |
|---|---|---|---|---|
| ADR-01 | Eigene schlanke MVC-Anwendung, gepflegte Einzelbibliotheken (FastRoute, Twig, Phinx, Monolog, phpdotenv) | wartbar, läuft auf Webhosting, keine Framework-Abhängigkeit | Laravel/Symfony: mehr Gewicht als nötig | umgesetzt |
| ADR-02 | Twig als Template-Sprache | automatisches Escaping gegen XSS | reines PHP-Templating | umgesetzt |
| ADR-03 | Datenbank kompatibel zu MySQL 8.0 und MariaDB 10.6+ | Netcup-Datenbanktyp noch offen | nur MySQL-8-Funktionen | umgesetzt, lokal mit MariaDB 10.11 getestet |
| ADR-04 | Prüfungen als Datensätze plus typisierte Zusatzfelder | neue Prüfung ohne Code und ohne neue Tabelle | Tabelle oder Template je Prüfung | umgesetzt (Gebühren, Zusatzfelder, Dokumente je Prüfung) |
| ADR-05 | Buchung speichert Momentaufnahme (Name, Preis, Gebühren, AGB-Version) | spätere Änderungen verfälschen nichts | Verweis auf aktuelle Stammdaten | umgesetzt, getestet (Abnahmetest 11) |
| ADR-06 | Kapazität per Zeilensperre in einer Transaktion | sicher bei gleichzeitigen Buchungen | Zählen ohne Sperre | umgesetzt (`SELECT … FOR UPDATE`), getestet (Abnahmetest 9) |
| ADR-07 | PayPal über REST-API (Orders v2) mit Guzzle hinter `PaymentGateway` | später Stripe ergänzbar; alte SDKs eingestellt | WooCommerce-Plugin | umgesetzt; Test mit echter Sandbox steht aus |
| ADR-08 | Zahlung gilt erst nach Server-Abgleich (Capture oder geprüfter Webhook) | Browser-Rückkehr ist fälschbar | Bestätigung per Weiterleitung | umgesetzt, getestet |
| ADR-09 | E-Mails über Warteschlange in der Datenbank, Versand per Cron | keine doppelten Mails, Wiederholung bei Fehler | Versand im Request | umgesetzt (Phase 4) |
| ADR-10 | Updates als signierte Pakete (Ed25519) | manipulierte Pakete werden erkannt | Prüfsumme im Paket | umgesetzt: Prüfschlüssel nur in `.env`, Sicherung und automatische Rücknahme, getestet (`OpsTest`) |
| ADR-11 | Backups verschlüsselt (libsodium), außerhalb des Webroots plus extern | Schutz bei Serverausfall | nur lokale Sicherung | umgesetzt: XChaCha20-Poly1305 (secretstream), nächtlich, monatliche Erinnerung zur externen Kopie, Wiederherstellung mit Rückfall getestet |
| ADR-12 | Kein LMS, keine Konten für Teilnehmende | Bestandsseite nutzt kein LMS; Gastbuchung ist einfacher | LearnPress nachbauen | umgesetzt |
| ADR-13 | Standorte als Datensätze | Standorte ändern sich ohne Code | feste Liste | umgesetzt |
| ADR-14 | Keine Tracking-Cookies, Schriften lokal, Karte erst nach Klick | kein Cookie-Banner nötig, schneller | Complianz weiter nutzen | umgesetzt |
| ADR-15 | Anwendungs-Firewall im Programm (Muster in Pfad und Anfrage, Punktekonto je IP, befristete Sperre) | läuft auf Webhosting ohne Serverzugriff | Fail2ban/WAF auf dem Server (auf Webhosting meist nicht einstellbar) | umgesetzt (Phase 5) |
| ADR-16 | Dateiwächter mit signierter Referenz (SHA-256-Liste, Ed25519) | erkennt veränderte und eingeschleuste Dateien; Referenz nicht unbemerkt fälschbar | unsignierte Prüfsummenliste | umgesetzt (Phase 5) |
| ADR-17 | Keine Shell aus der Verwaltung; Notfälle über feste Befehle in `bin/console` per SSH | keine Befehlsausführung aus Benutzereingaben | Web-Terminal | umgesetzt (Phase 5) |
| ADR-18 | Designsystem: Standardwerte im Code (`DesignSchema`), nur Abweichungen in der Datenbank (`design_tokens`); ausgegeben als CSS-Variablen in einer statischen Datei mit Prüfsumme im Namen (`public/media/design/design-<hash>.css`) | Website-Aussehen ohne CSS-Bearbeitung änderbar; kein PHP-Aufruf pro CSS-Abruf, Browser-Cache ein Jahr, neue Datei bei jeder Änderung; Zurücksetzen = Abweichungen löschen | freies CSS-Feld in der Verwaltung (unsicher), Inline-`<style>` (verstößt gegen CSP), CSS bei jedem Aufruf aus PHP (langsamer) | umgesetzt (0.8.0), getestet (`DesignSystemTest`, `DesignCssUsageTest`) |
