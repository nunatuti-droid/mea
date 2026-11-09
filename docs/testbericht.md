# Testbericht

Stand: 30.10.2026, Programmstand Phase 6. Alle Tests lokal ausgeführt: PHP 8.3.6, MariaDB 10.11, Apache 2.4.58, Chromium 153 (Playwright), Lighthouse 12.8.2. Auf dem Netcup-Server sind die Tests nach der Installation zu wiederholen (`docs/golive.md`).

## Zusammenfassung

| Bereich | Ergebnis |
|---|---|
| Automatische Tests (PHPUnit) | **99 Tests, 559 Prüfungen, alle bestanden** |
| Verbindliche Abnahmetests 7.9 (1–12) | alle bestanden (`AcceptanceTest`) |
| Alte Adressen der WordPress-Sitemap | 93 von 93 führen zu Seite, Weiterleitung oder 410 |
| Barrierefreiheit (axe-core) | 0 Verstöße: alle öffentlichen Seiten und alle Verwaltungsseiten, Desktop und 375 px |
| Lighthouse mobil, 28 Seiten | Leistung 99–100, Barrierefreiheit 100, Best Practices 100, SEO 100 |
| Geschützte Dateien über Apache | nicht abrufbar, mit beiden Ordner-Varianten |
| Gefundene und behobene Fehler | 3 (siehe unten) |

## Automatische Tests

Aufruf: `vendor/bin/phpunit`. Die Integrationstests bauen die Testdatenbank bei jedem Lauf neu auf.

| Testklasse | Inhalt (Abschnitt 21 des Auftrags) |
|---|---|
| `AcceptanceTest` | Abnahmetests 7.9: neue Prüfung nur über die Verwaltung, Veröffentlichung, Übersicht, Detailseite, SEO, Preise und Termine, Buchung mit Zahlung, falscher Betrag, Kapazität, deaktivierte Prüfung, bestehende Buchungen, Startprüfungen |
| `DynamicExamTest` | Entwurf, deaktiviert, archiviert, volle Termine, abgelaufene Reservierung, Anmeldeschluss, keine erfundenen Preise |
| `AdminSecurityTest` | Anmeldung mit 2FA, kein Wiederverwenden von Codes, Kontosperre, Rollen und Rechte, CSRF, widerrufene Sitzungen, letzter Administrator, erneute Bestätigung, keine Geheimnisse im Protokoll, PayPal-Webhook (Signatur, Wiederholungen), E-Mail-Vorlagen |
| `SecurityTest` | SQL-Einschleusung, gespeichertes XSS, Upload-Missbrauch, Einmal-Einladungslinks, Zugriff auf fremde Buchungen |
| `ConcurrencyTest` | 12 gleichzeitige Prozesse buchen 3 Plätze; Nachweis, dass eine Buchung auf die Sperre des Termins wartet (der Test schlägt ohne Sperre fehl, geprüft) |
| `FirewallTest` | Angriffsmuster, normale Anfragen, automatische Sperre und Ablauf, Erlaubnis- und Sperrliste, Fehlanmeldungen, eigene IP, Wartungsmodus |
| `OpsTest` | Sicherung und Wiederherstellung, manipulierte Sicherung, Rückfall bei fehlerhafter Wiederherstellung, Update mit Rücknahme, fehlgeschlagener Selbsttest, fremde, veränderte und ältere Pakete |
| `SchedulerTest` | Löschfristen, Erinnerungen genau einmal, nächtlicher Takt und Wiederholung nach Fehler, Aufräumen |
| `MigrationTest` | alle alten Adressen, Platzhalter-Weiterleitungen, 404-Protokoll, Weiterleitungen in der Verwaltung, Buchungsübernahme, Go-live-Prüfung, lokale Adresse wird nie gesperrt |
| `HttpTest`, `ContactFormTest` | Sicherheitsheader, HSTS, noindex, Sitemap, Weiterleitungen, Kontaktformular mit Spam-Schutz und CSRF |
| Unit-Tests | Preisberechnung, Passwortregeln, TOTP-Zeitfenster, Feiertage NRW, HTML-Bereinigung, Geldbeträge, Sommerzeit, IP-Bereiche, Verschlüsselung der Sicherungen, Dateiwächter |

## Browser-Tests

| Test | Ergebnis |
|---|---|
| `tests/e2e/smoke.js` (öffentliche Seiten, Desktop und 375 px, axe-core, Konsole, seitliches Scrollen, Ablauf Prüfung → Termin → Buchung, Mobilmenü) | bestanden |
| `tests/e2e/admin.js` (Anmeldung mit 2FA, alle Verwaltungsseiten inkl. „Weiterleitungen“ und „Buchung übernehmen“, Desktop und 375 px, axe-core, Konsole) | bestanden |

## Ladezeit und Qualität (Lighthouse, mobil)

Gemessen mit Lighthouse 12 (Handy-Profil mit gedrosselter Verbindung und CPU) gegen Apache auf demselben Rechner, `APP_ENV=production`, `APP_NOINDEX=false`. Das sind Laborwerte: Auf dem echten Server kommen Netzwerk und Serverleistung hinzu. Werte nach dem Livegang mit PageSpeed Insights prüfen.

| Seite | Leistung | Barrierefreiheit | Best Practices | SEO | LCP | CLS | TBT | KB |
|---|---|---|---|---|---|---|---|---|
| `/` | 99 | 100 | 100 | 100 | 2 s | 0 | 0 ms | 310 |
| `/agb/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 49 |
| `/avgs/` | 100 | 100 | 100 | 100 | 1,9 s | 0 | 0 ms | 268 |
| `/avgs/ganzheitliche-betreuung/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 6 ms | 191 |
| `/avgs/mea-plus/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 5 ms | 191 |
| `/avgs/stabilisierung/` | 100 | 100 | 100 | 100 | 1,8 s | 0 | 0 ms | 191 |
| `/beschaeftigten-qualifizierung/` | 100 | 100 | 100 | 100 | 1,5 s | 0 | 0 ms | 175 |
| `/datenschutz/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 49 |
| `/fachkraeftegewinnung/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 0 ms | 217 |
| `/faq/` | 100 | 100 | 100 | 100 | 1,2 s | 0 | 0 ms | 50 |
| `/impressum/` | 100 | 100 | 100 | 100 | 1,2 s | 0 | 8 ms | 49 |
| `/karriere/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 66 |
| `/kontakt/` | 100 | 100 | 100 | 100 | 1,2 s | 0 | 0 ms | 51 |
| `/leistungen/` | 100 | 100 | 100 | 100 | 1,8 s | 0 | 0 ms | 359 |
| `/onboarding/` | 99 | 100 | 100 | 100 | 2 s | 0 | 0 ms | 261 |
| `/preise/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 50 |
| `/pruefungen/telc-deutsch-a1/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 0 ms | 198 |
| `/pruefungen/telc-deutsch-a2-b1/` | 100 | 100 | 100 | 100 | 1,6 s | 0 | 15 ms | 147 |
| `/pruefungen/telc-deutsch-a2/` | 100 | 100 | 100 | 100 | 1,9 s | 0 | 8 ms | 198 |
| `/pruefungen/telc-deutsch-b1/` | 100 | 100 | 100 | 100 | 1,6 s | 0 | 5 ms | 147 |
| `/pruefungen/telc-deutsch-b2/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 2 ms | 147 |
| `/pruefungen/telc-deutsch-c1/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 0 ms | 197 |
| `/sprachkurse/` | 100 | 100 | 100 | 100 | 1,7 s | 0 | 0 ms | 200 |
| `/standorte/` | 100 | 100 | 100 | 100 | 1,2 s | 0 | 0 ms | 50 |
| `/standorte/herne/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 50 |
| `/standorte/marl/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 50 |
| `/telc-pruefungszentrum/` | 100 | 100 | 100 | 100 | 1,8 s | 0 | 0 ms | 199 |
| `/ueber-uns/` | 100 | 100 | 100 | 100 | 1,4 s | 0 | 0 ms | 65 |

Grenzwerte für „gut“ nach Google: LCP ≤ 2,5 s, CLS ≤ 0,1. Alle Seiten liegen darunter.

## Geschützte Dateien (Apache 2.4)

Geprüft mit beiden Ordner-Varianten aus `docs/deployment.md`: Document Root auf `public/` und Ausweichlösung über `/.htaccess` im Projektordner.

| Adresse | Ergebnis (beide Varianten) |
|---|---|
| `/`, `/telc-pruefungszentrum/`, `/verwaltung/anmelden/` | 200 |
| `/assets/css/app.css` | 200 mit Cache-Header |
| `/hier-herunterladen/` | 301 auf `/avgs/` |
| `/.env`, `/composer.json`, `/src/Core/App.php`, `/vendor/autoload.php`, `/integrity.json`, `/storage/backups/`, `/storage/logs/app.log`, `/docs/betrieb.md` | 404 (Datei wird nicht ausgeliefert) |
| `/phinx.php`, `/media/x.php` | 403 (PHP außer `index.php` gesperrt) |

## Gefundene und behobene Fehler in Phase 6

1. **Anwendungsschutz:** SQL-Muster in der Adresse wurden nicht erkannt, wenn sie Leerzeichen enthielten (die Abfrage wurde vor der Prüfung mit „+“ kodiert). Behoben; durch `SecurityTest` abgedeckt.
2. **Fehlende Weiterleitungen:** 7 Adressen der alten Sitemap (Theme-Menüs, Autorenseite) lieferten 404. Jetzt 410 bzw. Platzhalter-Regeln für ganze alte Bereiche.
3. **Überschriftenreihenfolge** auf `/leistungen/` (h3 direkt nach h1). Behoben; Lighthouse Barrierefreiheit jetzt 100.

Zusätzlich abgesichert: Kommen alle Anfragen wegen eines falsch eingestellten Proxys von `127.0.0.1`, sperrt der Anwendungsschutz niemanden und meldet das als kritisches Ereignis.

## Nicht getestet und warum

| Punkt | Grund | Wann |
|---|---|---|
| Zahlung mit echtem PayPal-Sandbox-Konto | Zugangsdaten fehlen; getestet mit Testdouble | vor dem Livegang (`golive.md` A) |
| Versand über echten SMTP-Server | Zugangsdaten fehlen; lokal Versand in Dateien | vor dem Livegang |
| Installation und Messung auf Netcup | Hosting-Zugang fehlt | Installation |
| Manuelle Prüfung mit Screenreader | braucht eine Person mit Erfahrung | vor dem Livegang |
| Lasttest mit vielen gleichzeitigen Besuchern | für die erwartete Besucherzahl nicht nötig; gleichzeitige Buchungen sind getestet | bei Bedarf |
| Wiederherstellung einer Sicherung aus einer neueren Programmversion | bewusst nicht unterstützt (`betrieb.md`) | – |
