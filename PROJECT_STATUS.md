# PROJECT_STATUS.md – MEA

Zentrale Projektübersicht. **Maßgeblich ist der Git-Code**; diese Datei fasst ihn zusammen und wird nur nach größeren Arbeiten oder auf Wunsch aktualisiert.
Neuer Chat: zuerst diese Datei lesen, dann `git log` und `git status` prüfen, dann arbeiten.
`belegt` = im Repo oder in Tests nachgewiesen. `nicht belegt` = nicht überprüfbar.

## 1. Git-Stand (Stand 09.11.2026)
- Branch `main`, Stand nach PR #10: `b9edb08`. Gesamte Entwicklung + Fix `3c2f7b6` kamen mit PR #7 (`8ba5532`).
- Der Anwendungscode auf `main` ist identisch mit `3c2f7b6` (Unterschied zu `main` nur diese Datei).
- `VERSION` im Repo: **0.9.5** (Vorbereitung des Releases 0.9.5; Anwendungscode unverändert gegenüber 0.9.4, enthält aber den E-Mail-Probe-Button-Fix, den das Paket 0.9.4 nicht enthält). Paket gebaut und auf mea3 eingespielt; Tag steht aus. Der Paketbau (`release:build`) schreibt `VERSION` weiterhin selbst.
- PRs: #1, #6, #7 gemergt; #2 bis #5 geschlossen (in `main` enthalten). Es gibt keine offenen PRs.
- Alte Branches bestehen noch (`phase-3` bis `phase-6`, `claude/project-thread-*`, `fix/email-test-button-wrap`); alle sind in `main` enthalten und können nach einer Wartefrist gelöscht werden.
- Keine CI (`.github/` fehlt). Update-Pakete 0.7.0 bis 0.9.5 liegen im Projektordner `installation/`, nicht im Repo.

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
- Auf `main` nicht erneut ausgeführt; der Anwendungscode ist seit `3c2f7b6` unverändert.

## 5. Bekannte Fehler und offene Punkte
- Keine CI; Tests laufen nur lokal.
- **Serverstand:** auf `mea3.ghazi-online.de` ist **0.9.5** installiert (09.11.2026 20:00, laut Verwaltung → Updates; Prüfschlüssel `872c342c…` passt zum Paket). Dateiwächter danach: ok, 0 Abweichungen. Letzte Vollsicherung vorher: 09.11.2026 19:53. Nächtliche Sicherung lief noch nie (Cron fehlt). Suchmaschinen-Indexierung ist gewollt gesperrt (Entwicklungsphase).
- Datenschutzerklärung (Entwurf) und AGB (Platzhalter) fachlich prüfen lassen; Lizenz „telc Deutsch C1 Hochschule“ klären.
- Echte Google-Profildaten eintragen (Link, Note, Anzahl, Datum).
- Plesk: Cron einrichten; `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 20M (Bewerbungs-PDFs).
- Zugänge/Unterlagen von MEA (PayPal, SMTP, Preise, Termine u. a.): Stand nicht belegt.
- Seitenbaukasten: Datensatzseiten (Leistung/Prüfung/Standort), Buchungsstrecke, Bewerbungsformular und 404-Seite sind nicht im Baukasten; nicht geprüft: Screenreader, Safari/Firefox.
- Behoben (Branch `claude/project-thread-oji744`, nicht gebaut): Mobiles Menü war abgedunkelt und nicht bedienbar, weil `.site-header` (sticky, z-index 50) einen Stapelkontext bildet und der Hintergrund (z 55) über dem Menü lag. Fix: `body.nav-open .site-header { z-index: 60 }` in `app.css`. Paket `installation/update-0.9.11.zip` gebaut (SHA-256 `93fbc790…650e0`), noch nicht eingespielt.
- Behoben zuvor: Überlauf auf `/verwaltung/email-vorlagen/4/` bei 375 px (Commit `3c2f7b6`).

- **Update 0.9.12 (Branch `claude/project-thread-as8q29`, gestapelt auf PR #15, nicht gebaut):** Neustrukturierung „MEA Campus“ nach Freigabe. Hauptmenü: Startseite, Campus (Übersicht, Bildungszentrum, Recruiting, Coaching & Beratung, Sprache & Beruf), Prüfungen (Prüfungen, Prüfungsvorbereitung), Standorte, Kontakt. „Über uns“, Karriere, FAQ, Preise im Footer. Neue Adressen: `/campus/`, `/bildungszentrum/`, `/recruiting/` (+ `/recruiting/onboarding/`), `/coaching-beratung/` (+ `/mea-plus/`, `/job-mentoring/`, `/ganzheitliche-betreuung/`), `/sprache-beruf/`, `/pruefungen/`, `/pruefungen/vorbereitung/`, `/pruefungen/preise/`. Keine Weiterleitungen alter Adressen (alte Adressen = 404); WordPress-Weiterleitungen werden entfernt. Daten (Leistungen, Menüs, Seiten, Startseite, Meta-Angaben) stellt `src/Install/CampusStructure.php` einmalig beim ersten Seitenaufruf nach dem Update um (`ContentPatches`, Schlüssel `campus-2026-11-11`; Menüs werden dabei neu aufgebaut, eigene Menüänderungen gehen verloren). Design unverändert.
- **Offen zu 0.9.12:** Seite „Prüfungsvorbereitung“ ist ein **Entwurf** mit Beispielwerten (Dauer, Termine, Preise) und muss vor der Veröffentlichung mit echten Angaben gefüllt werden (Verwaltung → Seiten). Update-Paket noch nicht gebaut. Campus-Abschnitt der Startseite nutzt die vorhandenen Leistungskarten (4 Bereiche), kein neues Kartenlayout.

- **Update 0.9.15 (Paket `installation/update-0.9.15.zip`, SHA-256 `d9eb91fd…b1089`, enthält 0.9.13/0.9.14; nicht eingespielt):** Alle Bereichs- und Unterseiten des Campus mit ausführlichen, aus den Referenzseiten ergänzten und neu formulierten Inhalten (`src/Install/ReferenceContent.php`, 15 Seiten: Hervorhebungen, Abschnitte, Ablauf, FAQ mit Antworten, Abschluss). Einmalig über `ContentPatches` `referenz-inhalte-2026-11-12` (überschreibt Texte der betroffenen Seiten); Vorlage `service.twig` erweitert. Offen: Startseite nicht erweitert; Standortseiten Recklinghausen/Bochum brauchen Adressen.
- **Update 0.9.13 (Branch `claude/project-thread-85yvuw`, gestapelt auf PR #16; Paket `installation/update-0.9.13.zip` gebaut; Fix des Megamenü-Hovers als **Update 0.9.14** (`installation/update-0.9.14.zip`, SHA-256 `6fc4e765…fa5a8`, enthält 0.9.13; gleiche Versionsnummer wird von der Verwaltung abgelehnt), nicht eingespielt):** Struktur und Navigation nach Vorgabe „Neue Struktur“ (Referenz: Campus-Seite). Hauptmenü: Startseite · **Campus ▾ als Megamenü** (vier Spalten: Bildungszentrum, Recruiting, Coaching & Beratung, Sprache & Beruf mit je Kurzbeschreibung und Kontakt-Link) · Über uns · Standorte · Kontakt · Button „Prüfung buchen“ (bestehend). Footer: Fördermöglichkeiten, Häufige Fragen, Preise, Karriere, Kontakt. **Adressen:** `/coaching/` (vorher `/coaching-beratung/`, keine Weiterleitung), neue Unterseiten `/bildungszentrum/{ausbildung,sprachtraining,pruefungen}/`, `/recruiting/unternehmen/`, `/coaching/{foerderung,teilnehmende,jobcenter}/`, `/sprache-beruf/{berufsdeutsch,unternehmen}/`, `/foerderung/`; Prüfungen und Prüfungsvorbereitung bleiben unter `/pruefungen/` (Buchung unverändert). Texte aus den Referenzseiten neu formuliert (`src/Install/ReferenceStructure.php`, einmalig über `ContentPatches` `referenz-2026-11-11`; bei Neuinstallation nach `CampusStructure`). Technik: Menü bis 3 Ebenen (`ContentRepository::menu`, Verwaltung erlaubt 3 Ebenen), Megamenü in `header.twig` + `app.css`, Coaching-Maßnahmen und Informationsseiten (`facts.info`) werden auf Bereichsseiten getrennt gezeigt, neues Kontaktthema „Bildungszentrum“. Menüeintrag „Prüfungsvorbereitung“ erscheint erst, wenn die Entwurfsseite veröffentlicht ist (sonst 404).
- **Tests 0.9.13:** PHPUnit komplett 179 Tests, 2.445 Assertions, grün (lokale MariaDB). E2E/axe und Sichtprüfung des Megamenüs bei 1440 und 390 px (Screenshots) nur manuell; E2E nicht ausgeführt.
- **Offen zu 0.9.13:** Standortseiten für Recklinghausen und Bochum fehlen (nur Marl und Herne/Wanne-Eickel sind als Standorte angelegt; Adressen von MEA nötig). Startseite ist bereits eine Langseite (Hero, Campus, Warum MEA, Prüfungen, Termine, Schritte, Stimmen, FAQ); keine neuen Abschnitte ergänzt. Über-uns-Inhalte der Bereiche (Referenz: je Bereich eine Seite) nicht übernommen; „Job-Mentoring“ bleibt als Maßnahme unter Coaching erhalten. Prüfungsvorbereitung weiter Entwurf mit Beispielwerten.

## 6. Nächster sinnvoller Schritt
Plesk: Cron einrichten und PHP-Limits prüfen (siehe Punkt 5). Release 0.9.5 ist gebaut (`installation/update-0.9.5.zip`, SHA-256 `47286ec6…b6714`) und eingespielt. Ein Git-Tag `v0.9.5` auf `b9edb08` fehlt noch (Push aus der Sitzung nicht erlaubt; manuell auf GitHub setzen).
