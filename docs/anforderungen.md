# Anforderungen und Nachweise

Stand: Phase 4 (Verwaltung, Buchung, Zahlung). ✓ = umgesetzt und geprüft, ◐ = teilweise, ○ = spätere Phase.

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
| Alte URLs: 301 auf neue Seiten, Demo-Seiten 410 | ✓ | `HttpTest::testOldUrlsRedirectOrAreGone` |
| Meta-Beschreibung und genau eine h1 je Seite | ✓ | `HttpTest::testPublicPageRendersWithSecurityHeaders` |
| Sitemap, robots.txt, strukturierte Daten (JSON-LD) | ✓ | `HttpTest::testSitemapIsValidXml` |
| E-Mail-Adresse nicht im Klartext | ✓ | `HttpTest` (alle Seiten) |
| Karte erst nach Klick | ✓ | `public/assets/js/app.js` |
| Barrierefreiheit WCAG 2.2 AA | ✓ (automatisch) | `tests/e2e/smoke.js` mit axe-core: 0 Verstöße auf 13 Seiten, Desktop und Handy. Eine manuelle Prüfung mit Screenreader steht noch aus. |
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

## Spätere Phasen

Umzug von WordPress und Abnahme (Phase 6).

## Offene Punkte bei MEA

- Dritter Standort: Adresse und Öffnungszeiten; Telefonnummer Herne bestätigen
- Netcup: Datenbanktyp und Version, Document Root auf `/public`, Cron im Minutentakt
- Logo als SVG, Erlaubnis für Partnerlogos, USt-IdNr.
- Datenschutzerklärung und AGB (Prüfung durch eine Fachperson nötig)
- PayPal- und SMTP-Zugang, WordPress-Export inkl. AVGS-PDFs
