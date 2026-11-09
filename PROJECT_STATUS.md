# PROJECT_STATUS.md – MEA Webauftritt und Verwaltung

Stand: 09.11.2026. Erstellt aus: Projekt-Threads, GitHub-Repo `nunatuti-droid/mea` (PRs, Branches), Projektordner `/mnt/project-files/` (insb. `installation/`, `seitenbaukasten/`).
Kennzeichnung: **belegt** = in Dokumenten, Threads, Paketen oder GitHub nachgewiesen. **nicht belegt** = konnte nicht überprüft werden.
Nicht gelesen: `release-key/` (Geheimnis, bewusst ausgelassen).

---

## 1. Kurzüberblick

- Neue, CMS-freie Website für das MEA Bildungszentrum (telc-Prüfungszentrum Herne, Standort Marl, Sprachkurse, AVGS u. a.) mit eigener Verwaltung unter `/verwaltung/`.
- **Höchste Paketversion im Projektordner: 0.9.4** (31.10.2026). Letzte *bestätigte* Installation auf dem Testserver: **0.7.1** (Thread „Prüfschlüssel und Update-Paket erzeugen"). Welche Version aktuell auf dem Server läuft: **nicht belegt**.
- Der Programmcode liegt **nicht im Branch `main`** (dort nur der Startcommit „Repository anlegen", Datei `.gitignore`). Er steckt in den offenen PRs/Branches (siehe Abschnitt 8) und in den Update-ZIPs.

## 2. Architektur (belegt)

| Bereich | Stand |
|---|---|
| Technik | Natives PHP 8.3/8.4 ohne Framework (FastRoute, Twig, Phinx, Monolog), MySQL 8 / MariaDB 10.6+, PDO mit Prepared Statements |
| Hosting | netcup / Plesk, Testumgebung `mea3.ghazi-online.de` (Dokumentenstamm `mea-app/public`), kein SSH, Befehle über Plesk „Geplante Aufgaben" (`php bin/console …`) |
| Struktur | `src/Core`, `src/Module/*`, `src/Admin/*`, `templates/` (public, admin, partials, blocks), `public/assets`, `migrations/`, `tests/` |
| Konsole | `bin/console`: `env:init`, `migrate`, `seed [--demo]`, `user:create-admin`, `cron`, `selftest`, `golive:check`, `update:install`, `backup:create`, `security:admin-access-reset` |
| Betrieb | Cron jede Minute (`php bin/console cron`): E-Mail-Warteschlange, Löschfristen, Sicherungen, Erinnerungen, Dateiwächter |
| Sicherheit | Argon2id, Pflicht-2FA (TOTP), CSRF, CSP ohne Inline-Skripte, Firewall mit Punktekonto/IP-Sperren, Dateiwächter (SHA-256, Ed25519-signiert), signierte Update-Pakete (`RELEASE_PUBLIC_KEY` in `.env`) |
| Seitenaufbau | Seitenbaukasten (ab 0.9.0): gemeinsamer `PageRenderer`, Templates `templates/blocks/*`, Seiten-CSS als Datei `public/media/design/seite-<id>-<hash>.css` |
| Designsystem | zentrale CSS-Variablen aus der Datenbank (ab 0.8.0) |
| Tests | zuletzt 178 automatische Tests (PHPUnit) grün, axe-core (WCAG 2.2 AA) ohne Befund (Angabe in Paketanleitungen 0.9.1–0.9.4) |

Architekturentscheidungen sind in `docs/adr/README.md` (ADR-10/11/15/16/17 u. a.) und `docs/anforderungen.md` der Phasen-Branches dokumentiert (laut PR-Texten; Dateien selbst in dieser Sitzung nicht geöffnet).

## 3. Versionen / Update-Pakete (belegt: `VERSION` in jedem ZIP geprüft)

| Version | Datum (Datei) | Inhalt (soweit belegt) |
|---|---|---|
| Basis `mea-app.zip` | – | Installationspaket, Branch `phase-6-golive` |
| `update-gesamt-2026-10-30` | 30.10. | Alle Änderungen „Punkte 1–10" + zweite Runde 1–8 (siehe Abschnitt 4), 118 Tests |
| 0.7.0 | 30.10. | Erstes signiertes Update-Paket |
| 0.7.1 | 30.10. | Vollständiger signierter Stand; **per Plesk auf Testserver eingespielt, Selbsttest bestanden (7.681 Dateien)** |
| 0.7.2 | 30.10. | Hero-Text entfernt, Backend-Menü als Akkordeon, Google-Sterne/Modul eintragbar (laut Thread) |
| **0.7.3** | 30.10. 23:07 | Paket vorhanden, enthält VERSION 0.7.3. Eigener Inhalt gegenüber 0.7.2: **nicht belegt** (0.7.4 „enthält alles aus 0.7.1 bis 0.7.3") |
| 0.7.4 | 30.10. | Google-Zeile im Hero kompakt/transparent (G, Sterne, „4,9 (10)"), Google-Karte im Footer halbtransparent |
| 0.7.5 | 31.10. | Updates direkt in der Verwaltung: Upload in Teilen, Installation im Hintergrund mit Live-Fortschritt (134 Tests). Diese Version selbst muss einmalig per Plesk-Konsole installiert werden |
| **0.7.6** | 31.10. 07:01 | Mehr Kontrast bei „Zulassungen und Partner"-Kästen. **Basis für das Designsystem** (Thread-Aussage) |
| 0.8.0 | 31.10. | Designsystem (Voraussetzung ≥ 0.6.0) |
| 0.9.0 | 31.10. | Seitenbaukasten |
| 0.9.1 | 31.10. | Neues Startbild (WebP, 4 Größen) |
| 0.9.2 | 31.10. | Sicherung als AES-256-ZIP mit Passwort |
| 0.9.3 | 31.10. | Vollständige Sicherung (DB + alle Dateien + LIESMICH) |
| **0.9.4** | 31.10. | Partnerlogos groß, ohne sichtbare Bezeichnung (aktuellstes Paket) |

Einspielen ab 0.7.5: Verwaltung → System → Updates → ZIP hochladen → „Update einspielen" (automatische Sicherung, Rücknahme bei Fehler). SHA-256-Summen stehen in den `update-0.8.0` bis `0.9.4-anleitung.md`.

## 4. Fertiggestellte Funktionen (belegt, Quelle `installation/aenderungen.md`)

**Gesamt-Update 30.10.2026 (Punkte 1–10, 118 Tests grün)**
1. Datenschutzerklärung (Entwurf, 18 Abschnitte) – **fachliche Prüfung offen**
2. Google: Bewertungen als Link + Sterne (manuell gepflegt), Google-Maps-Karte auf Standortseiten (erst nach Klick/Einwilligung)
3. Cookie-Einwilligung (Banner, Einstellungen, Nachweis ohne IP, Löschung nach 3 Jahren; GA/Meta-Pixel/YouTube nur nach Eintrag und Zustimmung)
4. Firewall/Schutz der Verwaltung (Angriffswerkzeuge abweisen, nur GET/HEAD/POST, Bot-Falle, optional IP-Beschränkung der Verwaltung)
5. Website-Status: Online/Offline mit Wartungsseite (503) und Schalter für Suchmaschinen-Indexierung
6. Design-Feinschliff (Abstände, Formulare, Überschriften)
7. Alle telc-Prüfungen nur im Prüfungszentrum Herne
8. telc-Deutsch A1–C1 mit Texten; C1 Hochschule und C2 als Entwurf ohne Preis
9. Prüfungsverwaltung: Kategorien, Schnellschalter, „deaktiviert = öffentlich ausgeblendet"
10. Kürzere Überschriften/Kurztitel, Texte leicht erweitert

**Zweite Runde (30.10. abends)**: Leistungen zuerst auf Startseite; Menüpunkt „Startseite"; Logo auf Login; Partner-Kacheln; Google-Sterne im Hero + Footer-Modul; Stimmen ein-/ausblendbar; Bewerbungsformular mit verschlüsseltem PDF-Upload und Verwaltung (`/karriere/bewerbung/`); kein Mailto-Link mehr; „Direkter Draht" → „Kontakt".

**Seit 0.7.x / 0.8–0.9**: Update direkt in der Verwaltung (0.7.5); Designsystem (0.8.0); Seitenbaukasten (0.9.0); Startbild (0.9.1); ZIP-Sicherung (0.9.2); Vollsicherung (0.9.3); Partnerlogos (0.9.4).

**Phasen 3–6 (PRs #1–#4, siehe Abschnitt 8)**: öffentliche Website mit dynamischer Prüfungsverwaltung; Verwaltung, Buchung mit PayPal, E-Mail-Warteschlange; Betrieb (Firewall, Dateiwächter, Sicherungen, Updates, Cron); Umzug/Go-live (alte URLs, Weiterleitungen, `golive:check`, Doku).

## 5. Verwaltungsbereich und Designsystem

**Verwaltung** (`/verwaltung/`; Rollen Administrator, Redaktion, Buchungsverwaltung, Autor/in; Rechte u. a. „System", „publish", „Bewerbungen"):
Dashboard, Prüfungen (Arten, Kategorien, Termine, Kalender), Buchungen, Leistungen, FAQ, Bewertungen, Menüs, Standorte, Mediathek, Firmendaten & SEO (inkl. Google-Bewertungen, Cookies und Dienste), Seiten + Globale Bausteine + Kopf-/Fußbereich (0.9.0), Kontakt/Bewerbungen, E-Mail-Vorlagen und -Protokoll, System (Website-Status, Sicherheit, Sicherungen, Updates, Cookie-Einwilligungen, Änderungsprotokoll, Systemprüfung), **Designsystem**.

**Designsystem (0.8.0, belegt durch `update-0.8.0-anleitung.md`)**: fünf Reiter (Typografie, Farben, Layout, Komponenten, Responsive), Live-Vorschau (Computer/Tablet/Handy), Speichern aktiviert Änderung, jedes Feld einzeln und gesamt auf Standard zurücksetzbar, eigene Schriften (WOFF2/WOFF, max. 2 MB), nur Administratoren. Mit Standardwerten Aussehen wie bisher. Anforderungsliste: Thread „Google-Bewertungen und Updates in der Verwaltung" (Nunas Auftrag vom 31.10.). Ältere Design-Basis: `DESIGN.md` (#3f8d7b / #0e3042, Plus Jakarta Sans lokal, Lucide-Icons; laut PR #1). Umbruchpunkte Tablet 900 px / Handy 600 px (laut Seitenbaukasten-Doku).
Hinweis: Ein eigener Thread zum Designsystem ist in der Threadliste **nicht** auffindbar; Umsetzung ist nur über das Paket 0.8.0 und dessen Anleitung belegt.

**Seitenbaukasten (0.9.0, Doku `seitenbaukasten/seitenbaukasten-dokumentation.md`)**: Alle 12 Seiten (Startseite inkl. `is_home`, Leistungen, telc-Prüfungszentrum, Preise, Standorte, Kontakt, FAQ, Über uns, Karriere, Impressum, Datenschutz, AGB) in Bausteinen; 40 Elemente; Stil je Gerät; Entwurf/Veröffentlichen; Versionen (max. 60); Konflikterkennung; globale Bausteine. Vergleich alt/neu: 18 von 20 Screenshots pixelgleich.

## 6. Google-Bewertungen und Website-Updates

**Google-Bewertungen** (belegt): Entscheidung von Nuna „Link und Sternebewertung" – **kein** automatischer Abruf der Google-API. Pflege manuell: Verwaltung → Firmendaten & SEO → Google-Bewertungen (Link, Note, Anzahl, Stand). Anzeige: Hero-Zeile (kompakt, transparent, ab 0.7.4), Footer-Modul, „Über uns". Ohne Link erscheint nichts. Kein Datenabruf beim Seitenaufruf. Stimmen unter Inhalte → Bewertungen je Zeile ein-/ausblendbar.
**Offen:** Eintrag des echten Profil-Links und der echten Werte (Beispielwerte 4,8/37 bzw. 4,9/10 sind nur Muster) – ob erfolgt: **nicht belegt**. Nuna nannte Profil „MEA Bildungszentrum GmbH" auf Google Maps; empfohlen wird der Teilen-Link statt der Suchadresse.

**Website-Updates** (belegt): Signierte Pakete (Ed25519). Ablauf: Sicherung → Wartungsmodus → Migration → Selbsttest → bei Fehler automatische Rücknahme. Korrekturen am Ablauf (Selbsttest im Wartungsmodus, keine Zeitgrenze, hängender Prozess) liegen als **PR #5** vor, noch nicht gemerged. Ab 0.7.5 Installation komplett in der Verwaltung (Upload in Teilen, Hintergrund-Job). Projektordner enthält zusätzlich Anleitungen je Version.

## 7. Geänderte Dateien (Auswahl, belegt aus `aenderungen.md`/Doku)

- Neue Module: `src/Module/Ops/SiteStatus.php`, `src/Module/Consent/*`, `src/Module/Applications/*`, `src/Module/Builder/*`, `src/Install/TelcGermanExams.php`, `src/Install/PrivacyPolicy.php`
- Verwaltung: `src/Admin/{SiteStatus,Consent,Application,Review,Pages,TemplateTexts}Controller.php`, `src/Admin/Resources/{ExamCategory,ExamType,Location,Service,Session,Review}Resource.php`, `src/Admin/AdminModule.php`
- Kern/Sicherheit: `src/Core/App.php`, `src/Core/Request.php`, `src/Core/HtmlSanitizer.php`, `src/Module/Security/Firewall.php`, `public/.htaccess`, `bin/console`
- Templates: `templates/public/*`, `templates/partials/*` (u. a. `google_rating.twig`, `consent.twig`, `exam_venue.twig`), `templates/blocks/*`, `templates/admin/*`
- Assets: `public/assets/css/{app,admin,builder*}.css`, `public/assets/js/{app,consent,builder}.js`
- Migrationen: `20261103100013`–`…20` (Herne, Kategorien, Kurztitel, Consent-Log, TelcExams, Datenschutz, Menü, Bewerbungen), `20261106100023_create_page_builder`
- Tests: u. a. `SiteStatusTest`, `ExamCenterTest`, `FirewallTest`, `ConsentTest`, `GoogleRatingTest`, `PrivacyPolicyTest`, `ApplicationTest`, `PageBuilderTest`
- Weitere Dateien: `installation/aenderungen.md`, `installation/zusammenfassung-update-2026-10-30.md`

Dateien aus 0.7.x–0.9.4, die nur in den ZIPs/Commits stehen (Update-Seite, Designsystem-Modul, Sicherung): vollständige Dateiliste **nicht einzeln geprüft**.

## 8. Repository-Stand (GitHub, belegt)

- `main`: nur Startcommit (`.gitignore`). **Kein Code auf `main`.**
- Offene PRs (alle ungemerged): #1 `phase-3-frontend` → main; #2 `phase-4-backend` → phase-3; #3 `phase-5-operations` → phase-4; #4 `phase-6-golive` → phase-5; #5 `claude/project-thread-hwfwvc` → phase-6-golive (Update-Ablauf-Korrekturen).
- Weitere Branches: `claude/project-thread-odq5v7` (Update in Verwaltung 0.7.5, Kontrast 0.7.6), `claude/project-thread-y7mqrh` (ZIP-Sicherung, Vollsicherung, Partnerlogos 0.9.2–0.9.4). **Ob diese Branches PRs haben oder Seitenbaukasten/Designsystem (0.8.0/0.9.0) enthalten, ist nicht belegt.**
- Pakete 0.7.x–0.9.4 liegen nur im Projektordner `installation/`, nicht im Repo.

## 9. Offene Aufgaben und bekannte Probleme

1. **Datenschutzerklärung fachlich prüfen** (Datenschutzbeauftragter ja/nein, AVV mit netcup, Datenweitergabe an telc, Löschfrist Bewerbungen 6 Monate). **AGB sind noch Platzhalter** (Go-live-Prüfliste meldet das).
2. **Lizenz „telc Deutsch C1 Hochschule"** bestätigen; C1 Hochschule/C2 sind Entwurf ohne Preis.
3. **Google-Profil-Link und echte Bewertungswerte** eintragen (siehe 6).
4. **Cron-Job** (`php bin/console cron`, jede Minute) in Plesk anlegen, falls nicht geschehen; ohne ihn keine E-Mails/Löschfristen.
5. **PHP-Grenzen in Plesk** für Bewerbungs-PDFs: `upload_max_filesize` ≥ 8M, `post_max_size` ≥ 20M.
6. **PR #5 und die Phasen-PRs #1–#4 mergen**; `main` ist leer. Bekannter Testfehler laut PR #5: `testEmailTemplatesAreSeededAndRender` erwartet 12 Vorlagen, es sind 13 (schlägt unabhängig von PR #5 fehl; später ggf. behoben, **nicht belegt**).
7. **Zugänge/Unterlagen von MEA** (laut PR #1/#4): PayPal- und SMTP-Zugang, Netcup-Zugang, 3 PDFs der alten Download-Seite, Preise und Termine, Rechtstexte, dritter Standort/Öffnungszeiten, USt-IdNr., Partnerlogo-Erlaubnis. Aktueller Erledigungsstand: **nicht belegt**.
8. **Einschränkungen Seitenbaukasten**: Datensatzseiten (Leistung/Prüfung/Standort), Buchungsstrecke, Bewerbungsformular, 404-Seite nicht im Baukasten; nicht geprüft: Screenreader durch Person, Safari/Firefox; Editor am besten ab ca. 1200 px.
9. **Weiter offene Wunschpunkte**: „Alle Texte aller Seiten im Backend bearbeitbar" (Punkt 9 der zweiten Runde) war „wartet auf Wahl"; Teil davon durch Seitenbaukasten/`TemplateTexts` adressiert, Vollständigkeit **nicht belegt**.
10. Threads **„Website-Updates für MEA umsetzen"** und **„Google-Bewertungen und Updates in der Verwaltung"** stehen auf „blockiert / braucht Eingabe" (Stand 30./31.10.).
11. Ältere Sicherungen (vor 0.9.3) sind unvollständig; nach Update einmal „Jetzt sichern" und ZIP extern aufbewahren.
12. Empfohlen vom Entwickler: Plesk Fail2Ban/ModSecurity aktivieren; Website-Status: Indexierung erst vor Livegang einschalten.

## 10. Nächste Entwicklungsschritte (Vorschlag aus belegten offenen Punkten)

1. Stand auf dem Server klären (installierte Version, Cron, PHP-Limits) und ggf. 0.9.4 einspielen.
2. Branches/PRs konsolidieren: Code der 0.7.5–0.9.4-Stände in einen mergebaren Hauptzweig bringen, `main` aktualisieren.
3. Google-Profil-Daten eintragen; Datenschutz/AGB juristisch prüfen lassen; Lizenzfrage C1 Hochschule klären.
4. Go-live-Check (`php bin/console golive:check`), danach Indexierung einschalten.
5. Restpunkte Seitenbaukasten (Datensatzseiten, feste Beschriftungen) bei Bedarf.

## 11. Pflege dieses Dokuments

Nach größeren Aufgaben aktualisieren (Version, Pakete, offene Punkte). Speicherorte: Projektordner `/mnt/project-files/PROJECT_STATUS.md` und Repo-Root `PROJECT_STATUS.md` (Branch `claude/project-thread-4ohyn8`, per PR).
