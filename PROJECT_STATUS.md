# PROJECT_STATUS.md – MEA

Zentrale Projektübersicht. **Maßgeblich ist der Git-Code**; diese Datei fasst ihn zusammen und wird nur nach größeren Arbeiten oder auf Wunsch aktualisiert.
Neuer Chat: zuerst diese Datei lesen, dann `git log` und `git status` prüfen, dann arbeiten.
`belegt` = im Repo oder in Tests nachgewiesen. `nicht belegt` = nicht überprüfbar.

## 1. Git-Stand (Stand 09.11.2026)
- Branch `main`, Commit `70d5001` (Merge PR #6). Davor `8ba5532` (Merge PR #7 = gesamte Entwicklung + Fix `3c2f7b6`).
- Der Anwendungscode auf `main` ist identisch mit `3c2f7b6` (Unterschied zu `main` nur diese Datei).
- `VERSION` im Repo: **0.6.0**. Der Code entspricht dem Update-Paket **0.9.4** (`src`, `templates`, `migrations`, `public/assets`, `bin` verglichen). `VERSION` wird beim Paketbau (`release:build`) überschrieben; Anpassung auf 0.9.4 ist offen.
- PRs: #1, #6, #7 gemergt; #2 bis #5 geschlossen (in `main` enthalten). Es gibt keine offenen PRs.
- Alte Branches bestehen noch (`phase-3` bis `phase-6`, `claude/project-thread-*`, `fix/email-test-button-wrap`); alle sind in `main` enthalten und können nach einer Wartefrist gelöscht werden.
- Keine CI (`.github/` fehlt). Update-Pakete 0.7.0 bis 0.9.4 liegen im Projektordner `installation/`, nicht im Repo.

## 2. Entwicklungsstand (belegt)
Neue CMS-freie Website für das MEA Bildungszentrum (PHP 8.3, MySQL/MariaDB, Twig) mit Verwaltung unter `/verwaltung/`.

**Öffentlich:** Startseite, Leistungen/AVGS, telc-Prüfungszentrum Herne (A1 bis C1, C1 Hochschule und C2 als Entwurf), Preise, Standorte (Google Maps nach Klick/Einwilligung), Kontakt, FAQ, Über uns, Karriere mit Bewerbungsformular (verschlüsselter PDF-Upload), Datenschutz-Entwurf, Buchung mit PayPal, Cookie-Banner, Online-/Offline-Schalter.
**Verwaltung:** Prüfungen/Kategorien/Termine/Kalender, Buchungen, Leistungen, Standorte, Menüs, FAQ, Bewertungen (Google-Bewertung als Link + Sterne, manuell gepflegt, kein API-Abruf), Mediathek, Firmendaten & SEO, E-Mail-Vorlagen, Benutzer mit Pflicht-2FA.
**Designsystem (0.8.0):** zentrale CSS-Variablen aus der Datenbank, Live-Vorschau, eigene Schriften.
**Seitenbaukasten (0.9.0):** alle 12 Seiten als Bausteine, Entwurf/Veröffentlichen, Versionen, globale Bausteine, Kopf-/Fußbereich.
**Betrieb:** Firewall, Dateiwächter (signiert), verschlüsselte Sicherungen (Vollsicherung, ZIP mit Passwort), signierte Updates direkt in der Verwaltung, Cron (`php bin/console cron`, jede Minute).

## 3. Wichtige technische Entscheidungen
Vollständig in `docs/adr/README.md` (ADR-01 bis ADR-19). Kern: eigene schlanke MVC-Anwendung (FastRoute, Twig, Phinx); Prüfungen/Standorte als Datensätze; Buchung mit Momentaufnahme und Zeilensperre; Zahlung erst nach Server-Abgleich; E-Mail über Datenbank-Warteschlange; Updates und Dateiwächter mit Ed25519; keine Shell aus der Verwaltung; Designsystem mit Standardwerten im Code und nur Abweichungen in der Datenbank; Seitenbaukasten als geprüfter JSON-Baum.
Nach Version-Updates: `php bin/console migrate` (23 Migrationen), danach `selftest`.

## 4. Testergebnisse (selbst ausgeführt am 09.11.2026 auf Code = `3c2f7b6`, isolierte lokale Test-Datenbank)
- PHPUnit: 178 Tests, 2.396 Assertions, grün.
- Migration auf leerer DB (23 Schritte) und Upgrade von `phase-6-golive` (3 Schritte): ok; `selftest` bestanden.
- Dateiwächter: ok, erkennt unerwartete Datei.
- E2E-Smoketest + axe (WCAG 2.2 AA), 13 Seiten bei 1366 und 375 px: grün. Admin-E2E: grün (nach Fix).
- Secret-Scan lokal über die Historie: keine Funde (GitHub-Scanning nicht verfügbar).
- Auf `main` (`70d5001`) nicht erneut ausgeführt; der Code ist unverändert.

## 5. Bekannte Fehler und offene Punkte
- `VERSION` steht auf 0.6.0 (siehe oben).
- Keine CI; Tests laufen nur lokal.
- **Serverstand nicht belegt:** bestätigt installiert ist nur 0.7.1 auf `mea3.ghazi-online.de` (30.10.2026). Ob 0.7.2 bis 0.9.4 eingespielt sind, ist unbekannt.
- Datenschutzerklärung (Entwurf) und AGB (Platzhalter) fachlich prüfen lassen; Lizenz „telc Deutsch C1 Hochschule“ klären.
- Echte Google-Profildaten eintragen (Link, Note, Anzahl, Datum).
- Plesk: Cron einrichten; `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 20M (Bewerbungs-PDFs).
- Zugänge/Unterlagen von MEA (PayPal, SMTP, Preise, Termine u. a.): Stand nicht belegt.
- Seitenbaukasten: Datensatzseiten (Leistung/Prüfung/Standort), Buchungsstrecke, Bewerbungsformular und 404-Seite sind nicht im Baukasten; nicht geprüft: Screenreader, Safari/Firefox.
- Behoben zuletzt: Überlauf auf `/verwaltung/email-vorlagen/4/` bei 375 px (Commit `3c2f7b6`).

## 6. Nächster sinnvoller Schritt
`VERSION` auf 0.9.4 setzen (ein kleiner eigener Commit), danach Serverstand klären und erst dann ein Update einspielen.
