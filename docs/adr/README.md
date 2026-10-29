# Architekturentscheidungen (ADR)

Festgelegt in Phase 2. Spalte „Stand“ zeigt, was in Phase 3 umgesetzt ist.

| Nr. | Entscheidung | Grund | Verworfen | Stand |
|---|---|---|---|---|
| ADR-01 | Eigene schlanke MVC-Anwendung, gepflegte Einzelbibliotheken (FastRoute, Twig, Phinx, Monolog, phpdotenv) | wartbar, läuft auf Webhosting, keine Framework-Abhängigkeit | Laravel/Symfony: mehr Gewicht als nötig | umgesetzt |
| ADR-02 | Twig als Template-Sprache | automatisches Escaping gegen XSS | reines PHP-Templating | umgesetzt |
| ADR-03 | Datenbank kompatibel zu MySQL 8.0 und MariaDB 10.6+ | Netcup-Datenbanktyp noch offen | nur MySQL-8-Funktionen | umgesetzt, lokal mit MariaDB 10.11 getestet |
| ADR-04 | Prüfungen als Datensätze plus typisierte Zusatzfelder | neue Prüfung ohne Code und ohne neue Tabelle | Tabelle oder Template je Prüfung | Datensätze umgesetzt und getestet; Zusatzfelder folgen mit dem Backend (Phase 4) |
| ADR-05 | Buchung speichert Momentaufnahme (Name, Preis, Gebühren, AGB-Version) | spätere Änderungen verfälschen nichts | Verweis auf aktuelle Stammdaten | Tabelle vorhanden (`bookings.snapshot_json`), Logik Phase 4 |
| ADR-06 | Kapazität per Zeilensperre in einer Transaktion | sicher bei gleichzeitigen Buchungen | Zählen ohne Sperre | Zählung umgesetzt, Sperre Phase 4 |
| ADR-07 | PayPal über REST-API (Orders v2) mit Guzzle hinter `PaymentGateway` | später Stripe ergänzbar; alte SDKs eingestellt | WooCommerce-Plugin | Phase 4 |
| ADR-08 | Zahlung gilt erst nach Server-Abgleich (Capture oder geprüfter Webhook) | Browser-Rückkehr ist fälschbar | Bestätigung per Weiterleitung | Phase 4 |
| ADR-09 | E-Mails über Warteschlange in der Datenbank, Versand per Cron | keine doppelten Mails, Wiederholung bei Fehler | Versand im Request | Phase 5 |
| ADR-10 | Updates als signierte Pakete (Ed25519) | manipulierte Pakete werden erkannt | Prüfsumme im Paket | Phase 5 |
| ADR-11 | Backups verschlüsselt (libsodium), außerhalb des Webroots plus extern | Schutz bei Serverausfall | nur lokale Sicherung | Phase 5 |
| ADR-12 | Kein LMS, keine Konten für Teilnehmende | Bestandsseite nutzt kein LMS; Gastbuchung ist einfacher | LearnPress nachbauen | umgesetzt |
| ADR-13 | Standorte als Datensätze | Standorte ändern sich ohne Code | feste Liste | umgesetzt |
| ADR-14 | Keine Tracking-Cookies, Schriften lokal, Karte erst nach Klick | kein Cookie-Banner nötig, schneller | Complianz weiter nutzen | umgesetzt |
