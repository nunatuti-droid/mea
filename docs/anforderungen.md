# Anforderungen und Nachweise

Stand: Phase 3 (öffentliche Website). ✓ = umgesetzt und geprüft, ◐ = teilweise, ○ = spätere Phase.

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
| Pflege im Backend | ○ | Phase 4 |

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
| 2FA, Rollen, Admin-Login | ○ | Phase 4 |

## Spätere Phasen

Buchung mit Überbuchungsschutz, PayPal und Webhooks, Backend mit 2FA (Phase 4); E-Mail-Warteschlange, Cron, Backups, Updates (Phase 5); Umzug und Abnahme (Phase 6).

## Offene Punkte bei MEA

- Dritter Standort: Adresse und Öffnungszeiten; Telefonnummer Herne bestätigen
- Netcup: Datenbanktyp und Version, Document Root auf `/public`, Cron im Minutentakt
- Logo als SVG, Erlaubnis für Partnerlogos, USt-IdNr.
- Datenschutzerklärung und AGB (Prüfung durch eine Fachperson nötig)
- PayPal- und SMTP-Zugang, WordPress-Export inkl. AVGS-PDFs
