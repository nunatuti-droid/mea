# MEA Bildungszentrum – Website

Neue Website für das MEA Bildungszentrum (Marl, Herne). Sie ersetzt WordPress (EduBlink, Elementor, WooCommerce, LearnPress) durch eine eigene, schlanke PHP-Anwendung.

Der Kern ist eine **dynamische Prüfungsverwaltung**: Jede Prüfung (z. B. telc Deutsch B1) ist ein Datensatz. Neue Prüfungen, Termine und Preise entstehen ohne Code, Templates oder neue Tabellen.

## Stand

| Phase | Inhalt | Stand |
|---|---|---|
| 1 | Bestandsaufnahme der alten Website | fertig |
| 2 | Architektur, Datenmodell (`docs/adr/`) | fertig |
| 3 | Öffentliche Website, Designsystem, Weiterleitungen, Tests | fertig |
| 4 | Verwaltung mit 2FA, Prüfungs- und Terminpflege, Kalender, Buchung, PayPal, E-Mail-Warteschlange | fertig |
| 5 | Betrieb: Schutzschicht, Dateiwächter, verschlüsselte Sicherungen, signierte Updates, nächtliche Wartung, Notfallbefehle | fertig |
| 6 | Umzug: Weiterleitungen aller alten Adressen, Buchungsübernahme, Go-live-Prüfung, Testbericht, Installations- und Go-live-Anleitung | **dieser Stand** |

| Dokument | Inhalt |
|---|---|
| [`docs/anforderungen.md`](docs/anforderungen.md) | Anforderungsmatrix mit Status (getestet, offen, blockiert) |
| [`docs/testbericht.md`](docs/testbericht.md) | Testergebnisse, Ladezeit, bekannte Einschränkungen |
| [`docs/deployment.md`](docs/deployment.md) | Installation auf dem Server (Netcup/Plesk) |
| [`docs/golive.md`](docs/golive.md) | Go-live-Checkliste und Rollbackplan |
| [`docs/betrieb.md`](docs/betrieb.md) | Betrieb, Sicherungen, Updates, Notfälle |
| [`docs/migration/alte-urls.txt`](docs/migration/alte-urls.txt) | alle Adressen der alten WordPress-Sitemap |
| [`docs/adr/`](docs/adr/README.md), [`DESIGN.md`](DESIGN.md) | Architekturentscheidungen, Designsystem (Variablen, Rollen, Cache, Sicherheit) |

## Voraussetzungen

- PHP 8.3 oder 8.4 mit `pdo_mysql`, `intl`, `mbstring`, `gd` (mit WebP), `sodium`
- MySQL 8.0+ oder MariaDB 10.6+
- Composer 2
- Für Browsertests: Node.js 20+

## Lokale Installation

```bash
composer install
cp .env.example .env          # Werte eintragen, APP_KEY erzeugen (siehe Kommentar)
# Datenbanken anlegen, z. B.:
#   CREATE DATABASE mea CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE DATABASE mea_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
bin/console migrate
bin/console seed --demo       # --demo nur lokal/Vorschau: Beispieltermine und -preise
bin/console serve             # http://127.0.0.1:8080
```

### Erster Zugang zur Verwaltung

```bash
bin/console user:create-admin name@firma.de Vorname Nachname
```

Der Befehl gibt einen Einladungslink aus (48 Stunden gültig, einmal nutzbar). Darüber legt die Person ihr Passwort fest und richtet die Zwei-Faktor-Anmeldung mit einer Authenticator-App ein. Danach ist die Verwaltung unter `/verwaltung/` erreichbar; weitere Personen werden dort unter „Benutzer“ eingeladen.

### Cronjob (jede Minute)

```
* * * * * cd /pfad/zur/website && php bin/console cron >/dev/null 2>&1
```

Der Cronjob gibt jede Minute abgelaufene Reservierungen frei und versendet E-Mails. Stündlich räumt er auf; nachts ab 2 Uhr erstellt er eine Sicherung, anonymisiert Daten nach Fristen, verschickt Terminerinnerungen, prüft die Programmdateien und führt die Systemprüfung aus. Fehler gehen per E-Mail an die Administratoren. Die Systemprüfung in der Verwaltung warnt, wenn der Cronjob länger als 15 Minuten nicht gelaufen ist.

### Betrieb, Sicherungen, Updates, Notfälle

Siehe [`docs/betrieb.md`](docs/betrieb.md). Kurz: `BACKUP_KEY` (optional) und `RELEASE_PUBLIC_KEY` in die `.env`; die `.env` getrennt von den Sicherungen aufbewahren; Sicherungen regelmäßig herunterladen und extern speichern.

### E-Mail und PayPal

In `.env`: `MAIL_DSN` (SMTP-Zugang), `MAIL_FROM`, `MAIL_FROM_NAME` sowie `PAYPAL_MODE`, `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_WEBHOOK_ID`. Ohne PayPal-Zugang ist die Online-Buchung gesperrt und die Website zeigt einen Hinweis. Ohne SMTP bleiben E-Mails in der Warteschlange und werden später gesendet. Webhook-Adresse bei PayPal: `https://DOMAIN/zahlung/paypal/webhook/` mit den Ereignissen `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED`.

`seed` legt Inhalte, Bilder (WebP in 480/960/1440/1920 px), Standorte, die Prüfungen A1–C1 als Entwurf ohne Preis, FAQ und Weiterleitungen an. `--demo` veröffentlicht die Prüfungen mit Beispielpreisen und -terminen und blendet einen Hinweis ein. Auf `APP_ENV=production` wird `--demo` verweigert.

## Tests

```bash
vendor/bin/phpunit                         # Unit- und Integrationstests (nutzt DB_NAME_TEST)
npm install && BASE_URL=http://127.0.0.1:8080 npm run e2e   # Browser + Barrierefreiheit (axe)
BASE=http://localhost:8080 ADMIN_EMAIL=… ADMIN_PASSWORD=… ADMIN_TOTP_SECRET=… node tests/e2e/admin.js   # Verwaltung (nur lokal)
```

`tests/Integration/AcceptanceTest.php` enthält die verbindlichen Abnahmetests 7.9 (1–12): Eine neue Prüfung wird über die Verwaltung angelegt, veröffentlicht, gebucht und bezahlt (PayPal als Testdouble).

Die Integrationstests bauen die Testdatenbank bei jedem Lauf neu auf. Deren Name muss „test“ enthalten.

## Aufbau

```
bin/console          Kommandozeile (migrate, seed, cron, backup:*, update:*, Notfallbefehle; ohne Argument: Liste)
migrations/          Datenbankschema (Phinx, reines SQL, mit Rücknahme)
public/              Document Root: index.php, CSS, JS, Schriften, erzeugte Bilder
src/Core/            Router, Request/Response, Datenbank, Twig, Sitzung, CSRF
src/Admin/           Verwaltung: Anmeldung, Rechte, Formular-Baukasten, Bereiche, Designsystem
src/Module/          Design (Designsystem: Werte, Prüfung, CSS-Variablen), Content, Exams, Bookings, Payments, Mail, Auth, Audit, Locations, Inquiries, Media, Seo,
                     Security (Firewall, Dateiwächter), Ops (Sicherung, Update, Wartungsjobs)
src/Install/         Startdaten (ContentSeeder) und Vorschaudaten (DemoSeeder)
templates/           Twig-Templates; ein Template für alle Prüfungen
seeds/media/         Original-Fotos und Logos
storage/             Logs, Original-Uploads, Sicherungen, Update-Rücknahmedaten (nicht öffentlich)
tests/               Unit, Integration, e2e
```

## Betrieb auf Netcup

Ausführlich in [`docs/deployment.md`](docs/deployment.md). Vor der Umschaltung: `php bin/console golive:check`.

- Document Root auf `public/` setzen. Falls das nicht geht, sorgt `/.htaccess` im Projektordner dafür, dass nur `public/` erreichbar ist (mit Apache getestet).
- `APP_NOINDEX=true` auf der Vorschau: Suchmaschinen werden ausgesperrt (`X-Robots-Tag`, `robots.txt`).
- PHP-Version in Plesk: 8.4.

## Sicherheit

- Zugangsdaten nur in `.env` (nicht im Repository).
- CSP ohne Inline-Skripte, CSRF auf allen Formularen, IP-Adressen nur als Hash.
- E-Mail-Adresse erscheint nicht im HTML-Quelltext; sie wird erst beim Klick zusammengesetzt.
- Karte (OpenStreetMap) lädt erst nach Zustimmung per Klick. Keine Tracking-Cookies.
- Verwaltung: Passwörter mit Argon2id, Zwei-Faktor-Anmeldung (TOTP) Pflicht, Backup-Codes, Kontosperre nach 5 Fehlversuchen, widerrufbare Sitzungen (30 Minuten Inaktivität, 12 Stunden maximal), erneute Bestätigung für kritische Aktionen, Rollen (Administrator, Redaktion, Buchungsverwaltung), Änderungsprotokoll ohne Geheimnisse.
- Schutzschicht: erkennt Angriffsmuster in Adressen, sperrt auffällige IP-Adressen befristet; Wartungsmodus.
- Dateiwächter: vergleicht Programmdateien nachts mit einer signierten Referenz.
- Sicherungen verschlüsselt (libsodium) außerhalb des Webroots, Updates nur als signierte Pakete mit automatischer Rücknahme.
- Keine Shell-Befehle aus der Verwaltung; Notfälle über feste Befehle per SSH.
- Buchung: Platz per Zeilensperre, bestätigt nur nach serverseitigem Einzug oder geprüftem Webhook, Betrag wird abgeglichen, doppelte Ereignisse werden erkannt.

## Rechtliches

Impressum, Datenschutzerklärung und AGB sind Platzhalter beziehungsweise Entwürfe. Sie müssen vor dem Livegang von einer Fachperson geprüft werden.

Schrift: Plus Jakarta Sans (SIL OFL, `public/assets/fonts/OFL-PlusJakartaSans.txt`). Icons: Lucide (ISC, `docs/LICENSE-lucide.txt`).
