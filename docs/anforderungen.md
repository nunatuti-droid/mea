# Anforderungen und Nachweise

Stand: Phase 6 (Umzug, Tests, Go-live-Vorbereitung). Status nach Abschnitt 25 des Auftrags:

- **✓ Getestet**: umgesetzt und mit dem genannten Test oder der genannten Prüfung nachgewiesen
- **◐ Implementiert**: vorhanden, aber noch nicht vollständig geprüft
- **○ Offen**: noch nicht umgesetzt oder nicht geprüft
- **⛔ Blockiert**: wartet auf Zugänge, Unterlagen oder Entscheidungen von MEA

Testbericht mit allen Ergebnissen: [`testbericht.md`](testbericht.md).

## Prüfungsverwaltung (Abnahmekriterien)

| Anforderung | Stand | Nachweis |
|---|---|---|
| Neue Prüfung ohne Code, Template oder Tabelle | ✓ | `tests/Integration/DynamicExamTest::testNewExamAppearsOnDetailOverviewPricesAndSitemap` |
| Neue Prüfung erscheint auf Detailseite, Übersicht, Preisliste, Filter, Sitemap | ✓ | dito |
| Entwurf ist öffentlich unsichtbar | ✓ | `testDraftIsHiddenEverywhere` |
| Deaktiviert: Seite sichtbar, nicht buchbar | ✓ | `testDisabledExamIsVisibleButNotBookable` |
| Archiviert: 301 auf die Übersicht, nicht in der Sitemap | ✓ | `testArchivedExamRedirectsToOverview` |
| Volle Termine sind nicht buchbar (bestätigt + laufende Reservierung) | ✓ | `testFullSessionIsNotBookable` |
| Abgelaufene Reservierung gibt den Platz frei | ✓ | `testExpiredReservationFreesTheSeat` |
| Anmeldeschluss beendet die Buchbarkeit | ✓ | `testDeadlinePassedIsNotBookable` |
| Keine erfundenen Preise (ohne Preis kein Betrag) | ✓ | `testExamWithoutPriceShowsNoInventedPrice` |
| Terminpreis vor Grundpreis, Pflichtgebühren addiert | ✓ | `tests/Unit/PriceCalculatorTest` |
| A1–C1 nur als Startdaten | ✓ | `src/Install/ContentSeeder.php` |
| Pflege im Backend | ✓ | Verwaltung unter `/verwaltung/` |

### Verbindliche Abnahmetests 7.9 (`tests/Integration/AcceptanceTest.php`)

| Nr. | Test | Stand | Testmethode |
|---|---|---|---|
| 1 | Neue Prüfungsart nur über das Backend angelegt | ✓ | `test01_02_ExamIsCreatedOnlyInBackendAndCanBePublished` |
| 2 | Speichern und veröffentlichen | ✓ | dito (mit Änderungsprotokoll und Versionen) |
| 3 | Öffentliche Übersicht zeigt sie automatisch | ✓ | `test03_04_05_AppearsInOverviewWithOwnUrlAndSeo` |
| 4 | Detailseite ohne neue PHP-Datei | ✓ | dito |
| 5 | Eigene URL und SEO-Metadaten (Titel, Beschreibung, Canonical, Sitemap) | ✓ | dito |
| 6 | Preise und Termine unabhängig pflegbar | ✓ | `test06_PricesAndSessionsAreMaintainedIndependently` |
| 7 | Vollständiger Buchungsprozess | ✓ | `test07_08_FullBookingWithPaymentAssigned` (Formular → PayPal → Rückkehr → bestätigt → E-Mail) |
| 8 | Zahlung korrekt zugeordnet | ✓ | dito; falscher Betrag wird nicht bestätigt: `test08_WrongAmountIsNotConfirmed` |
| 9 | Kapazitätsgrenzen | ✓ | `test09_CapacityIsEnforced`, `test09_ReservationHoldsSeatDuringPayment` |
| 10 | Deaktivierte Prüfung nicht buchbar | ✓ | `test10_DisabledExamCannotBeBooked` |
| 11 | Bestehende Buchungen bleiben bei Änderungen erhalten | ✓ | `test11_ExistingBookingsSurviveChanges` (Momentaufnahme, Benachrichtigung bei Terminänderung, Löschschutz) |
| 12 | Vorhandene Prüfungsarten funktionieren weiter | ✓ | `test12_SeededExamsStillWork` |

Die Tests laufen gegen PayPal als Testdouble. Ein Test mit dem echten PayPal-Sandbox-Konto steht aus, sobald MEA die Zugangsdaten liefert.

## Öffentliche Website

| Anforderung | Stand | Nachweis |
|---|---|---|
| Alle Seiten aus der Datenbank, eigenes Design (#3f8d7b / #0e3042) | ✓ | `DESIGN.md`, Screenshots im PR |
| Hochgeladene Fotos verwendet | ✓ | 24 Bilder in `seeds/media`, WebP in 4 Größen |
| Alte URLs: 301 auf neue Seiten, Demo-Seiten 410 | ✓ | `MigrationTest::testEveryOldUrlIsHandled` (alle 93 Adressen der WordPress-Sitemap) |
| Meta-Beschreibung und genau eine h1 je Seite | ✓ | `HttpTest::testPublicPageRendersWithSecurityHeaders` |
| Sitemap, robots.txt, strukturierte Daten (JSON-LD) | ✓ | `HttpTest::testSitemapIsValidXml` |
| E-Mail-Adresse nicht im Klartext | ✓ | `HttpTest` (alle Seiten) |
| Karte erst nach Klick | ✓ | `public/assets/js/app.js` |
| Barrierefreiheit WCAG 2.2 AA | ◐ | Automatisch ✓: axe-core 0 Verstöße (`tests/e2e/smoke.js`), Lighthouse 100 auf allen 28 Seiten. Manuelle Prüfung mit Screenreader und Tastatur durch eine Person: offen. |
| Kein horizontales Scrollen auf dem Handy | ✓ | dito |

## Sicherheit

| Anforderung | Stand | Nachweis |
|---|---|---|
| CSP ohne Inline-Skripte, nosniff, Frame-Schutz, HSTS nur unter HTTPS | ✓ | `HttpTest`, Browser-Konsole ohne CSP-Fehler |
| CSRF-Schutz | ✓ | `ContactFormTest::testMissingCsrfIsRejected` |
| Spam-Schutz (Honeypot, Mindestzeit, Ratenbegrenzung) | ✓ | `ContactFormTest` |
| IP nur als Hash gespeichert | ✓ | `ContactFormTest::testValidInquiryIsStored` |
| Keine Zugangsdaten im Code | ✓ | `.env.example` ohne Werte, `.env` in `.gitignore` |
| Admin-Login mit 2FA (TOTP), kein Wiederverwenden eines Codes | ✓ | `AdminSecurityTest::testFullLoginWithTotpAndNoReplay`, `tests/Unit/AuthHelpersTest` |
| Kontosperre nach Fehlversuchen, Sicherheits-E-Mail | ✓ | `AdminSecurityTest::testWrongPasswordLocksAccount` |
| Rollen und Rechte | ✓ | `AdminSecurityTest::testRolesAreEnforced` |
| CSRF in der Verwaltung, widerrufene Sitzungen | ✓ | `testPostWithoutCsrfIsRejected`, `testRevokedSessionIsLoggedOut` |
| Letzter Administrator geschützt, erneute Bestätigung | ✓ | `testLastAdminCannotBeDisabledAndCriticalChangesNeedReauth` |
| Keine Geheimnisse im Änderungsprotokoll | ✓ | `testAuditLogNeverStoresSecrets` |
| PayPal-Webhook: Signatur geprüft, doppelte Ereignisse ignoriert | ✓ | `testWebhookRejectsBadSignatureAndIgnoresDuplicates` |
| E-Mail-Vorlagen in einer Sandbox (kein Code in Platzhaltern) | ✓ | `testEmailTemplatesAreSeededAndRender` |
| HTML aus dem Editor bereinigt | ✓ | `tests/Unit/AdminHelpersTest::testSanitizerRemovesDangerousMarkup` |
| Barrierefreiheit der Verwaltung | ✓ (automatisch) | `tests/e2e/admin.js`: axe ohne Verstöße auf allen Verwaltungsseiten, Desktop und Handy |

## Betrieb (Phase 5)

| Anforderung | Stand | Nachweis |
|---|---|---|
| Anwendungs-Firewall: Angriffsmuster, Punktekonto, befristete Sperre, Erlaubnisliste | ✓ | `FirewallTest` (7 Tests) |
| Fehlversuche bei Anmeldung/2FA führen zur IP-Sperre | ✓ | `FirewallTest::testFailedLoginsCountTowardsBan` |
| Eigene IP kann nicht gesperrt werden | ✓ | `FirewallTest` |
| Wartungsmodus: Besucher 503, Verwaltung erreichbar | ✓ | `FirewallTest` |
| Dateiwächter mit signierter Referenz (geändert, fehlend, unerwartet, Skripte in Medien) | ✓ | `tests/Unit/OpsUnitTest`, manuell: 12.871 Dateien in ca. 3 s |
| Sicherung verschlüsselt, Rechte 0600, außerhalb des Webroots | ✓ | `OpsTest::testBackupAndRestoreRoundTrip` |
| Manipulierte oder falsch verschlüsselte Sicherung wird erkannt | ✓ | `OpsTest::testTamperedBackupIsRejected`, `OpsUnitTest` |
| Wiederherstellung mit Sicherung vorher und automatischem Rückfall | ✓ | `OpsTest::testFailedRestoreRollsBackToPreviousState` |
| Updates nur mit gültiger Signatur, Prüfsumme je Datei, keine Rückstufung | ✓ | `OpsTest::testRejectsForeignTamperedAndOldPackages` |
| Update mit Sicherung, Selbsttest, automatischer Rücknahme; manuelle Rücknahme | ✓ | `OpsTest::testUpdateInstallAndRevert`, `testFailedSelfTestRollsBackUpdate` |
| Löschfristen (Anfragen, unbezahlte Buchungen, bezahlte erst nach Aufbewahrungsfrist) | ✓ | `SchedulerTest::testRetentionAnonymizesOnlyDueRecords` |
| Terminerinnerung genau einmal | ✓ | `SchedulerTest::testRemindersAreSentOnceForConfirmedBookingsInWindow` |
| Nächtliche Jobs einmal pro Nacht, Wiederholung nach Fehler | ✓ | `SchedulerTest::testNightlyJobsRunOnceAfterTwoAndRetryAfterFailure` |
| Keine Shell aus dem Web; Notfallbefehle per SSH | ✓ | `bin/console`, `docs/betrieb.md` |
| Überwachung: Systemprüfung (Cron, Sicherung, externe Kopie, Dateiwächter, Updates, Sicherheit) | ✓ | Verwaltung → System; nächtlicher Job `health` mit E-Mail |

Die Löschfristen (z. B. 10 Jahre für bezahlte Buchungen) sind Voreinstellungen und müssen von einer Fachperson bestätigt werden.

## Zusätzliche Sicherheitstests (Phase 6)

| Anforderung | Stand | Nachweis |
|---|---|---|
| SQL-Einschleusung wird als Text behandelt | ✓ | `SecurityTest::testSqlInjectionPayloadsAreTreatedAsData` (vorbereitete Abfragen; Muster zusätzlich vom Anwendungsschutz abgewiesen) |
| Gespeichertes XSS wird in der Verwaltung maskiert | ✓ | `SecurityTest::testStoredScriptIsEscapedInBackend` |
| Upload-Missbrauch (PHP als Bild, SVG mit Skript, falsches PDF) | ✓ | `SecurityTest::testUploadsRejectScriptsAndDisguisedFiles` |
| Einladungslinks nur einmal gültig | ✓ | `SecurityTest::testInvitationTokenWorksOnlyOnce` |
| Buchungsstatus nur mit korrektem Schlüssel, nicht zwischengespeichert | ✓ | `SecurityTest::testCustomerCannotSeeBookingWithoutCorrectKey` |
| Gleichzeitige Buchungen: Sperre auf dem Termin | ✓ | `ConcurrencyTest` (12 parallele Prozesse auf 3 Plätze; Nachweis, dass die Buchung auf die Sperre wartet) |
| Geschützte Dateien (.env, src, vendor, storage, Sicherungen) nicht abrufbar | ✓ | Apache 2.4 mit `public/` als Document Root und mit Ausweichlösung über `/.htaccess` geprüft (Testbericht) |
| Lokale Adresse wird nie gesperrt (Schutz bei falsch eingestelltem Proxy) | ✓ | `MigrationTest::testLoopbackAddressIsNeverBanned` |

## Umzug und Go-live (Phase 6)

| Anforderung | Stand | Nachweis |
|---|---|---|
| Inhalte übernommen und redaktionell überarbeitet | ◐ | In Phase 3 aus der alten Website übernommen (`ContentSeeder`). Redaktionelle Freigabe durch MEA offen. |
| Medien übertragen | ◐ | 24 Fotos und Logos ✓. Die 3 PDF-Downloads der alten Seite (`/hier-herunterladen/`) ⛔: Dateien fehlen noch. |
| Alte URLs mit neuen Zielen abgeglichen, Weiterleitungen getestet | ✓ | `docs/migration/alte-urls.txt`, `MigrationTest::testEveryOldUrlIsHandled`, `golive:check` |
| Weiterleitungen in der Verwaltung pflegbar, Protokoll nicht gefundener Adressen | ✓ | `MigrationTest::testRedirectsAreManagedInBackend`, `testWildcardRulesAndNotFoundLog` |
| Laufende Buchungen aus dem alten Shop übernehmen | ✓ | Verwaltung → Buchungen → „Buchung übernehmen“, `MigrationTest::testBookingsFromOldShopCanBeTakenOver` |
| Datenbankmigrationen getestet (hoch, zurück, hoch) | ✓ | jede Migration lokal geprüft; Testdatenbank wird bei jedem Lauf neu aufgebaut |
| Buchungsabläufe vollständig geprüft | ✓ | Abnahmetests 7.9 mit PayPal-Testdouble |
| Zahlungsanbieter im Testmodus verifiziert | ⛔ | PayPal-Sandbox-Zugang fehlt |
| Backups erstellen und Wiederherstellung testen | ✓ | `OpsTest`; auf dem Server vor dem Livegang wiederholen (`docs/golive.md`) |
| SMTP-Versand getestet | ⛔ | SMTP-Zugang fehlt (lokal nur Dateiversand geprüft) |
| Sicherheitskonfiguration geprüft | ✓ | Systemprüfung, `golive:check`, Apache-Test |
| SEO-Metadaten, Sitemap, robots.txt | ✓ | `HttpTest`, Lighthouse SEO 100 auf allen Seiten (ohne `APP_NOINDEX`) |
| Mobile Darstellung | ✓ | `tests/e2e/smoke.js` (375 px), Lighthouse mobil |
| Ladezeit / Core Web Vitals | ✓ (Labor) | Lighthouse mobil: Leistung 99–100, LCP ≤ 2,0 s, CLS 0 auf allen 28 Seiten (lokaler Server; auf Netcup wiederholen) |
| Monitoring eingerichtet | ◐ | Systemprüfung, nächtlicher Gesundheitscheck mit E-Mail ✓; externe Erreichbarkeitsüberwachung auf dem Server einrichten (`docs/golive.md`) |
| Go-live- und Rollbackplan | ✓ | [`golive.md`](golive.md) |
| Installations-, Betriebs- und Notfallanleitung | ✓ | [`deployment.md`](deployment.md), [`betrieb.md`](betrieb.md) |
| Installation auf Netcup | ○ | Hosting-Zugang fehlt; Anleitung und Prüfbefehl liegen bereit |

## Offen und blockiert (vor dem Livegang)

| Punkt | Status | Wer |
|---|---|---|
| Datenschutzerklärung, AGB, Impressum (USt-IdNr.) fachlich prüfen und einsetzen | ⛔ | MEA mit Fachperson |
| Löschfristen bestätigen (Voreinstellung 10 Jahre für bezahlte Buchungen) | ⛔ | MEA mit Steuerberatung |
| PayPal-Zugang (Sandbox, dann live) und Testkauf | ⛔ | MEA |
| SMTP-Zugang und Testversand | ⛔ | MEA |
| Netcup: Zugang, PHP 8.4, Datenbank, Document Root, Cron | ⛔ | MEA |
| 3 PDF-Dateien der alten Download-Seite (16k, MEA Plus, Stabilisierung) | ⛔ | MEA (aus WordPress-Mediathek herunterladen) |
| Preise und Termine der Prüfungen eintragen | ⛔ | MEA (es werden keine Preise erfunden) |
| Dritter Standort, Telefonnummer Herne, Logo als SVG, Erlaubnis für Partnerlogos | ⛔ | MEA |
| Manuelle Prüfung mit Screenreader | ○ | Person mit Screenreader-Erfahrung |
| Inhalte redaktionell freigeben | ○ | MEA |
