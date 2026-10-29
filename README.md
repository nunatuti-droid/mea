# MEA Bildungszentrum – Website

Neue Website für das MEA Bildungszentrum (Marl, Herne). Sie ersetzt WordPress (EduBlink, Elementor, WooCommerce, LearnPress) durch eine eigene, schlanke PHP-Anwendung.

Der Kern ist eine **dynamische Prüfungsverwaltung**: Jede Prüfung (z. B. telc Deutsch B1) ist ein Datensatz. Neue Prüfungen, Termine und Preise entstehen ohne Code, Templates oder neue Tabellen.

## Stand

| Phase | Inhalt | Stand |
|---|---|---|
| 1 | Bestandsaufnahme der alten Website | fertig |
| 2 | Architektur, Datenmodell (`docs/adr/`) | fertig |
| 3 | Öffentliche Website, Designsystem, Weiterleitungen, Tests | **dieser Stand** |
| 4 | Buchung, PayPal, Backend mit 2FA | offen |
| 5 | E-Mail-Warteschlange, Cron, Backups, Updates | offen |
| 6 | Umzug und Abnahme | offen |

Anforderungen und Nachweise: [`docs/anforderungen.md`](docs/anforderungen.md). Designregeln: [`DESIGN.md`](DESIGN.md).

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

`seed` legt Inhalte, Bilder (WebP in 480/960/1440/1920 px), Standorte, die Prüfungen A1–C1 als Entwurf ohne Preis, FAQ und Weiterleitungen an. `--demo` veröffentlicht die Prüfungen mit Beispielpreisen und -terminen und blendet einen Hinweis ein. Auf `APP_ENV=production` wird `--demo` verweigert.

## Tests

```bash
vendor/bin/phpunit                         # Unit- und Integrationstests (nutzt DB_NAME_TEST)
npm install && BASE_URL=http://127.0.0.1:8080 npm run e2e   # Browser + Barrierefreiheit (axe)
```

Die Integrationstests bauen die Testdatenbank bei jedem Lauf neu auf. Deren Name muss „test“ enthalten.

## Aufbau

```
bin/console          Kommandozeile (migrate, seed, serve)
migrations/          Datenbankschema (Phinx, reines SQL, mit Rücknahme)
public/              Document Root: index.php, CSS, JS, Schriften, erzeugte Bilder
src/Core/            Router, Request/Response, Datenbank, Twig, Sitzung, CSRF
src/Module/          Content, Exams, Locations, Inquiries, Media, Seo
src/Install/         Startdaten (ContentSeeder) und Vorschaudaten (DemoSeeder)
templates/           Twig-Templates; ein Template für alle Prüfungen
seeds/media/         Original-Fotos und Logos
storage/             Logs und Original-Uploads (nicht öffentlich)
tests/               Unit, Integration, e2e
```

## Betrieb auf Netcup (Vorschau)

- Document Root auf `public/` setzen. Falls Plesk das nicht erlaubt, wird das in Phase 6 gelöst; `storage/`, `src/` und `.env` dürfen nie öffentlich erreichbar sein.
- `APP_NOINDEX=true` auf der Vorschau: Suchmaschinen werden ausgesperrt (`X-Robots-Tag`, `robots.txt`).
- PHP-Version in Plesk: 8.4.

## Sicherheit

- Zugangsdaten nur in `.env` (nicht im Repository).
- CSP ohne Inline-Skripte, CSRF auf allen Formularen, IP-Adressen nur als Hash.
- E-Mail-Adresse erscheint nicht im HTML-Quelltext; sie wird erst beim Klick zusammengesetzt.
- Karte (OpenStreetMap) lädt erst nach Zustimmung per Klick. Keine Tracking-Cookies.

## Rechtliches

Impressum, Datenschutzerklärung und AGB sind Platzhalter beziehungsweise Entwürfe. Sie müssen vor dem Livegang von einer Fachperson geprüft werden.

Schrift: Plus Jakarta Sans (SIL OFL, `public/assets/fonts/OFL-PlusJakartaSans.txt`). Icons: Lucide (ISC, `docs/LICENSE-lucide.txt`).
