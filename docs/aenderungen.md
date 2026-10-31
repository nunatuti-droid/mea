# Änderungsprotokoll: Website-Updates und Erweiterungen

Auftrag vom 30.10.2026 („Projektanweisungen – Website-Updates und Erweiterungen“).
Jede Änderung wird hier festgehalten. Das Gesamt-Update wird erst erstellt, wenn alle Punkte erledigt und freigegeben sind.

## Punkt 5: Website online/offline und Suchmaschinen-Indexierung

**Wo:** Verwaltung → System → Website-Status (`/verwaltung/website-status/`), nur mit Recht „System“ (Administrator).

**Was neu ist**
- Schalter „Website online“. Ausgeschaltet sehen Besucher eine Wartungsseite mit Logo, Hinweistext, Telefon, WhatsApp und Standorten. Der Server antwortet mit Status 503 und „Retry-After“, damit Suchmaschinen die Seite nicht als dauerhaft weg werten.
- Der Hinweistext der Wartungsseite ist im Backend änderbar (max. 600 Zeichen).
- Auch offline erreichbar bleiben: die Verwaltung, PayPal-Rückmeldungen (`/zahlung/`), Bilder/CSS und `robots.txt`.
- Schalter „Suchmaschinen dürfen die Website aufnehmen“. Ausgeschaltet: `noindex` im HTML-Kopf, Header `X-Robots-Tag: noindex, nofollow` und `robots.txt` mit `Disallow: /`. Eingeschaltet: normale `robots.txt` (Verwaltung, Buchung und Zahlung gesperrt) mit Sitemap.
- Solange nichts gespeichert wurde, gilt wie bisher der Wert `APP_NOINDEX` aus der `.env`.
- Jede Umschaltung wird im Änderungsprotokoll mit Name und Zeit gespeichert.
- Hinweisleiste oben in der Verwaltung, solange die Website offline oder die Indexierung gesperrt ist.
- Die getrennte technische Wartung bei Updates (`storage/maintenance.flag`) bleibt unverändert.

**Dateien:** `src/Module/Ops/SiteStatus.php` (neu), `src/Admin/SiteStatusController.php` (neu), `templates/admin/site/status.twig` (neu), `templates/public/offline.twig` (neu), `src/Core/App.php`, `src/Module/Seo/SeoController.php`, `src/Admin/AdminModule.php`, `templates/admin/layout.twig`, `public/assets/css/app.css`, `public/assets/css/admin.css`, `tests/Integration/SiteStatusTest.php` (neu).

**Nebenbei:** Text der Vorschau-Leiste gekürzt („Vorschau mit Beispieldaten: Preise und Termine sind nicht echt.“).

**Geprüft:** 107 automatische Tests grün (4 neue für diesen Punkt), Wartungsseite und Verwaltungsseite am Desktop und Handy angesehen.

## Punkt 7: Alle telc-Prüfungen nur im Prüfungszentrum Herne

**Was neu ist**
- Neues Merkmal je Standort: „Zertifiziertes telc-Prüfungszentrum“ (Verwaltung → Standorte). Herne ist markiert, Marl nicht.
- Beim Anlegen eines Prüfungstermins lässt sich nur noch ein Prüfungszentrum als Ort wählen. Andere Standorte werden abgelehnt.
- Künftige Termine in Marl wurden nach Herne verlegt, sofern keine echten Buchungen daran hängen (Beispieldaten zählen nicht).
- Hinweis „Prüfungsort: Herne … persönlich vor Ort und auf Papier; Online-Prüfungen bieten wir nicht an“ auf der Prüfungsübersicht und bei den Terminen jeder Prüfung. Im Anmeldeformular ein kurzer Satz dazu.
- Standortseite Herne: „Zertifiziertes telc-Prüfungszentrum“. Standortseite Marl: „An diesem Standort finden keine telc-Prüfungen statt“ mit Link nach Herne.
- Startseite: Überschrift „telc-Prüfungszentrum Herne“ und Satz zum Prüfungsort.
- SEO-Titel der Prüfungen und der Prüfungsseite: „in Herne“ statt „in Marl & Herne“; Beschreibung der Preisseite angepasst. Nur Texte, die noch dem Original entsprachen, wurden geändert.
- Beratung, Kurse und AVGS bleiben an beiden Standorten (Texte dort unverändert).

**Dateien:** `migrations/20261103100013_exam_center_herne.php` (neu), `templates/partials/exam_venue.twig` (neu), `src/Module/Locations/LocationRepository.php`, `src/Core/App.php`, `src/Admin/Resources/LocationResource.php`, `src/Admin/Resources/SessionResource.php`, `src/Install/ContentSeeder.php`, `src/Install/DemoSeeder.php`, `templates/public/exams_index.twig`, `templates/public/exam_show.twig`, `templates/public/booking_form.twig`, `templates/public/location.twig`, `templates/public/home.twig`, `public/assets/css/app.css`, `tests/Integration/ExamCenterTest.php` (neu), `tests/Integration/AcceptanceTest.php`.

**Nach dem Hochladen nötig:** `migrate` ausführen.

**Geprüft:** alle automatischen Tests grün (3 neue), Seiten am Bildschirm angesehen.

## Punkt 9: Prüfungsverwaltung im Backend

**Was neu ist**
- **Prüfungskategorien** (Verwaltung → Prüfungen → Prüfungskategorien): Name, Kurzname, Einleitungstext, Sortierung, aktiv/inaktiv. Start mit drei Kategorien nach telc.net: „Allgemeinsprachliche Deutschprüfungen“, „Berufsbezogene Deutschprüfungen“, „Deutschprüfungen für Pflege und Medizin“. Eine Kategorie mit zugeordneten Prüfungen kann nicht gelöscht werden.
- Jede Prüfung bekommt im Formular eine **Kategorie** zur Auswahl (ersetzt das freie Textfeld). Die bisherigen Prüfungen A1 bis C1 und A2-B1 sind „Allgemeinsprachlich“ zugeordnet.
- **Schnellschalter in der Prüfungsliste**: „Deaktivieren“ bzw. „Wieder veröffentlichen“ mit einem Klick (mit Rückfrage). Vor dem Veröffentlichen wird geprüft, ob Kurzbeschreibung und Preis vorhanden sind.
- **Deaktiviert heißt jetzt: öffentlich ausgeblendet.** Deaktivierte Prüfungen erscheinen nicht mehr in Übersicht, Startseite, Preisliste und Sitemap, sind nicht buchbar, und ihre Seite leitet vorübergehend auf die Prüfungsübersicht um. Vorher waren sie sichtbar, aber nicht buchbar.
- Texte jeder Prüfung (Kurzbeschreibung, Beschreibung, Für wen, Voraussetzungen, Ablauf) waren schon im Formular bearbeitbar; unverändert.
- Liste der Prüfungsarten: neue Spalte und neuer Filter „Kategorie“.
- **Website:** Die Prüfungsübersicht ist nach Kategorien gegliedert (Überschrift und Einleitung je Kategorie). Sobald es Prüfungen in mehr als einer Kategorie gibt, filtern die Schaltflächen oben nach Kategorie statt nach Niveau. Auf Karten und Prüfungsseiten steht „telc · Kurzname der Kategorie“.
- Jede Statusänderung steht im Änderungsprotokoll.

**Dateien:** `migrations/20261103100014_exam_categories.php` (neu), `src/Admin/Resources/ExamCategoryResource.php` (neu), `src/Admin/Resources/ExamTypeResource.php`, `src/Admin/ExamAdminController.php`, `src/Admin/Crud/Resource.php`, `src/Admin/AdminModule.php`, `src/Admin/MediaController.php`, `templates/admin/crud/index.twig`, `templates/admin/layout.twig`, `public/assets/css/admin.css`, `src/Module/Exams/ExamRepository.php`, `src/Module/Exams/ExamController.php`, `templates/public/exams_index.twig`, `templates/public/exam_show.twig`, `templates/partials/macros.twig`, `public/assets/css/app.css`, `src/Install/ContentSeeder.php`, Tests.

**Nach dem Hochladen nötig:** `migrate` ausführen.

**Geprüft:** 108 automatische Tests grün, Liste in der Verwaltung und Übersicht auf der Website angesehen.

## Punkt 6: Design-Feinschliff

**Was geändert wurde (alle Seiten, nur Gestaltung)**
- **Abstände:** Abschnittsabstand von bis zu 96 px auf höchstens 72 px reduziert (mobil 44 px). Abstand unter Abschnittsüberschriften von 48 px auf 32 px. Kopfbereich der Unterseiten flacher. Zwei gleichfarbige Abschnitte hintereinander bekommen keinen doppelten Abstand mehr. Dadurch sind die Abstände auf allen Unterseiten gleich.
- **5-Schritte-Raster („Unser Rekrutierungsprozess im Überblick“):** alle fünf Karten stehen am Bildschirm jetzt in einer Reihe; die fünfte Karte rutscht nicht mehr allein in die nächste Zeile. Auf kleineren Bildschirmen umbrechen sie gleichmäßig.
- **Überschriften:** Abschnittsüberschriften dürfen die volle Breite nutzen (die Überschrift oben bleibt einzeilig); Einleitungstexte bleiben angenehm schmal. Mehrzeilige Überschriften werden ausgeglichen umbrochen statt mit einem einzelnen Wort in der zweiten Zeile. Die Überschrift im grünen Beratungs-Band ist etwas kleiner und bleibt einzeilig.
- **Formulare (Kontakt, Anmeldung):** Formular steht in einer weißen Karte mit Schatten; Felder größer und weicher (abgerundet, hellerer Hintergrund), deutlicher Hover- und Fokuszustand, eigene Pfeile in Auswahllisten, Zustimmungs-Häkchen in einem eigenen Kästchen, Absende-Schaltfläche mit leichtem Schatten (mobil volle Breite).
- **Keine Unterstreichungen** mehr bei Telefonnummern, WhatsApp, Standortnamen und Standortangaben (Unterstreichung erst beim Darüberfahren).
- Verwaltung: Such- und Filterleiste der Listen steht wieder in einer Zeile.

**Dateien:** `public/assets/css/app.css`, `public/assets/css/admin.css`.

**Geprüft:** Browser-Test aller Hauptseiten bei 1280 px und 375 px inkl. Barrierefreiheit (axe) ohne Befund.

## Punkt 10: Überschriften einzeilig, Texte etwas ausgebaut

**Was geändert wurde**
- Alle Überschriften automatisch am Bildschirm (1280 px und 1024 px) geprüft.
- Kartentitel der Leistungen zeigen jetzt den **Kurztitel** (z. B. „Onboarding“, „Sprachkurse“, „MEA Plus“, „Job-Mentoring“), damit sie einzeilig bleiben. Der volle Titel steht weiter auf der Leistungsseite. Der Kurztitel ist in der Verwaltung unter Leistungen → „Kurztitel für Menüs und Karten“ änderbar. Für die drei AVGS-Unterleistungen wurden Kurztitel ergänzt (nur wo noch keiner gesetzt war).
- Abschnittsüberschriften etwas kleiner (max. 34 px statt 38 px) und mit voller Breite; das grüne Beratungs-Band steht jetzt einspaltig, die Überschrift bleibt einzeilig.
- Unterseiten-Kopf: Titel bekommt mehr Breite und ist etwas kleiner.
- Wo eine sehr lange Überschrift neben einem Bild steht (z. B. „Onboarding für internationale Fachkräfte“ oder „Individuelle Bildungswege für Arbeitsuchende und Beschäftigte“), passt sie technisch nicht in eine Zeile. Sie wird dort gleichmäßig auf zwei Zeilen verteilt. Kürzere Formulierungen sind im Backend jederzeit möglich.
- Die Hero-Überschrift der Startseite bleibt bewusst zweizeilig (Gestaltung).
- **Texte leicht erweitert** (ohne neue Behauptungen): Einleitung der Prüfungsübersicht, Leistungsbereich der Startseite und die Beratungs-Bänder auf Startseite, Prüfungen, FAQ, Leistungen, Über uns und Standardseiten.

**Dateien:** `public/assets/css/app.css`, `templates/partials/macros.twig`, `templates/public/service.twig`, `templates/public/home.twig`, `templates/public/exams_index.twig`, `templates/public/faq.twig`, `templates/public/page.twig`, `templates/public/page_about.twig`, `templates/public/services_index.twig`, `src/Admin/Resources/ServiceResource.php`, `src/Install/ContentSeeder.php`, `migrations/20261103100015_short_service_titles.php` (neu).

**Nach dem Hochladen nötig:** `migrate` ausführen.

## Punkt 4: Firewall und Schutz des Verwaltungsbereichs

**Bereits vorhanden (unverändert):** Anmeldung nur mit Passwort und Zwei-Faktor-Code, Kontosperre nach 5 Fehlversuchen, Begrenzung der Anmeldeversuche je Verbindung, Schutz gegen Formular-Fälschung (CSRF), Erkennung von Angriffsmustern (SQL-Einschleusung, Skript-Einschleusung, Verzeichnis-Ausbruch), automatische IP-Sperren mit Punktekonto, manuelle Sperr- und Freigabeliste, Dateiwächter.

**Neu**
- **Angriffswerkzeuge abweisen:** bekannte Scan- und Angriffsprogramme (z. B. sqlmap, Nikto, WPScan, Nuclei) werden am Kennzeichen ihres Programms erkannt und abgewiesen; das zählt 5 Punkte für eine Sperre. Normale Browser und Suchmaschinen sind nicht betroffen.
- **Nur erlaubte Anfragearten:** GET, HEAD und POST. TRACE, PUT, DELETE usw. werden mit 405 abgewiesen (in der Anwendung und zusätzlich in `public/.htaccess`).
- **Bot-Falle im Anmeldeformular:** ein für Menschen unsichtbares Feld; wird es ausgefüllt, wird die Anmeldung abgewiesen und 5 Punkte vergeben.
- **Fehlversuche sperren die Verbindung:** 10 Fehlversuche in 10 Minuten führen zu einer Sperre der Verbindung (das Konto sperrt schon nach 5).
- **Optional: Verwaltung nur aus bestimmten Netzen** (Verwaltung → Sicherheit → Zugang zur Verwaltung). Von anderen Adressen erscheint die Verwaltung als „nicht gefunden“. Schutz vor Selbstaussperrung: die eigene aktuelle Adresse muss enthalten sein. Notfall: Konsolenbefehl `security:admin-access-reset`. Standard: keine Einschränkung.
- **`public/.htaccess`:** versteckte Dateien (`.env`, `.git` …) werden nie ausgeliefert, Server-Signatur ausgeblendet.
- Neue Ereignisarten im Sicherheitsprotokoll; alle Änderungen am Zugang im Änderungsprotokoll.

**Hinweis:** Dieser Schutz ergänzt die Firewall des Hosters (Netcup/Plesk), ersetzt sie aber nicht. Empfehlung: in Plesk „Fail2Ban“ und die „Web Application Firewall (ModSecurity)“ aktivieren, falls verfügbar.

**Dateien:** `src/Module/Security/Firewall.php`, `src/Core/App.php`, `src/Admin/AuthController.php`, `src/Admin/SecurityController.php`, `templates/admin/security.twig`, `templates/admin/auth/login.twig`, `public/assets/css/admin.css`, `public/.htaccess`, `bin/console`, `tests/Integration/FirewallTest.php`.

**Geprüft:** 111 automatische Tests grün (3 neue).

## Punkt 3: Cookie-Einwilligung (Grundgerüst, fertig nutzbar)

**Was neu ist**
- **Banner beim ersten Besuch** mit drei gleichwertigen Schaltflächen: „Alle akzeptieren“, „Nur notwendige“, „Einstellungen“. Kein Vorab-Häkchen, kein Ablehnen-Verstecken.
- **Einstellungen** mit Schaltern je Kategorie: Notwendig (immer an), Statistik, Marketing, Externe Medien. Jede Kategorie erklärt Zweck, Anbieter und mögliche Übermittlung in die USA.
- **Nichts Optionales vor der Einwilligung:** Google Analytics und Meta-Pixel werden erst nach Zustimmung nachgeladen. Sind sie in der Verwaltung nicht eingetragen, werden sie nie geladen, nicht angezeigt und auch in der Sicherheitsrichtlinie (CSP) nicht freigegeben.
- **Karten:** werden ohne Einwilligung erst nach Klick geladen; mit Einwilligung „Externe Medien“ automatisch. Der Kartenlader akzeptiert bereits Google-Maps-Einbettungen (für Punkt 2).
- **Widerruf und Änderung jederzeit** über „Cookie-Einstellungen“ im Fußbereich jeder Seite und auf der Karte. Beim Widerruf wird die Seite neu geladen, damit bereits geladene Dienste wegfallen.
- **Nachweis (Art. 7 Abs. 1 DSGVO):** jede Auswahl wird mit zufälliger Kennung, Auswahl, Version und Zeitpunkt gespeichert, **ohne IP-Adresse**. Ansicht: Verwaltung → System → Cookie-Einwilligungen (mit Suche nach Kennung). Löschung automatisch nach 3 Jahren.
- **Neue Abfrage bei Änderungen:** Kommen Dienste hinzu oder wird die „Version der Cookie-Hinweise“ erhöht, werden alle Besucher erneut gefragt.
- **Verwaltung → Firmendaten & SEO → Cookies und Dienste:** Google-Analytics-Mess-ID, Meta-Pixel-ID, YouTube ja/nein, Version. Mit Formatprüfung.
- Cookie „mea_consent“: 12 Monate, nur eigene Domain, SameSite=Lax, bei HTTPS „Secure“.

**Noch offen:** Die tatsächlich genutzten Dienste (GA, Meta-Pixel, YouTube) und die passende Datenschutzerklärung (Punkt 1). Die Texte im Banner sollten zusammen mit der Datenschutzerklärung von einer fachkundigen Person geprüft werden.

**Dateien:** `src/Module/Consent/ConsentConfig.php` (neu), `src/Module/Consent/ConsentController.php` (neu), `src/Admin/ConsentAdminController.php` (neu), `templates/partials/consent.twig` (neu), `templates/admin/consent.twig` (neu), `public/assets/js/consent.js` (neu), `migrations/20261103100016_create_consent_log.php` (neu), `src/Core/App.php`, `src/Admin/SettingsController.php`, `src/Admin/AdminModule.php`, `src/Module/Ops/Scheduler.php`, `templates/layout/base.twig`, `templates/partials/footer.twig`, `templates/public/location.twig`, `templates/admin/layout.twig`, `public/assets/js/app.js`, `public/assets/css/app.css`, `tests/Integration/ConsentTest.php` (neu).

**Nach dem Hochladen nötig:** `migrate` ausführen.

**Geprüft:** 114 automatische Tests grün (3 neue), Banner, Einstellungen, Speichern und erneutes Laden im Browser getestet, Barrierefreiheit (axe) ohne Befund.

## Punkt 2 (Teil): Google-Bewertungen als Link und Sternebewertung

Entscheidung von Nuna: **„Link und Sternebewertung“** (kein automatischer Abruf über die Google-Schnittstelle).

**Was neu ist**
- Verwaltung → Firmendaten & SEO → **Google-Bewertungen**: Link zum Unternehmensprofil, Sternebewertung, Anzahl, Stand. Die Werte werden von Hand so übernommen, wie Google sie anzeigt. Es wird nichts erfunden oder automatisch abgerufen.
- Anzeige auf „Über uns“ (Abschnitt „Was unsere Teilnehmenden sagen“) und im Fußbereich: Sterne, „4,8 von 5 aus 37 Google-Bewertungen (Stand …)“ und Link „Bewertungen auf Google ansehen“. Die Zahlen hier sind nur ein Beispiel.
- Datenschutz: Beim Seitenaufruf werden keine Daten an Google übertragen, erst der Klick auf den Link führt zu Google. Deshalb ist dafür keine Cookie-Einwilligung nötig.
- Ohne eingetragenen Link erscheint nichts.
- Nebenbei behoben: Die Sterne bei Bewertungen waren nur als Umriss zu sehen. Jetzt sind sie gefüllt, bei Werten wie 4,8 werden fünf Sterne gezeigt, bei 4,5 vier.

**Noch offen:** Link zum Google-Profil sowie Google Maps statt OpenStreetMap auf den Standortseiten.

**Dateien:** `templates/partials/google_rating.twig` (neu), `templates/public/page_about.twig`, `templates/partials/footer.twig`, `templates/partials/macros.twig`, `templates/_icons.svg`, `src/Admin/SettingsController.php`, `public/assets/css/app.css`, `tests/Integration/GoogleRatingTest.php` (neu).

## Punkt 8: telc-Deutschprüfungen A1 bis C2

Grundlage: Lizenz des Prüfungszentrums Herne für „Deutsch A1 bis C2“ (Angabe von Nuna). Alle Fakten (Prüfungsteile, Dauer, Zielgruppe) stammen aus den Prüfungsseiten auf telc.net. Preise wurden nicht erfunden.

**Was neu ist**
- **A1, A2, A2·B1, B1, B2, C1:** ausführliche, sachliche Texte zu Zielgruppe, Prüfungsteilen, Ablauf und Dauer, jeweils mit Hinweis „Prüfung im telc-Prüfungszentrum Herne, vor Ort, auf Papier“. Die Texte werden nur ersetzt, wenn die Kurzbeschreibung noch dem ursprünglichen Starttext entspricht. Eigene Änderungen in der Verwaltung bleiben also erhalten.
- **Neu: telc Deutsch C1 Hochschule und telc Deutsch C2** als **Entwurf ohne Preis**. Sie sind öffentlich noch nicht sichtbar. Zum Veröffentlichen in der Verwaltung → Prüfungen den Preis eintragen und auf „Wieder veröffentlichen“ klicken.
- Alle Prüfungen liegen in der Kategorie „Allgemeinsprachliche Prüfungen“.

**Bitte prüfen:** Ist „telc Deutsch C1 Hochschule“ von eurer Lizenz abgedeckt? Falls nicht, den Entwurf einfach nicht veröffentlichen.

**Dateien:** `src/Install/TelcGermanExams.php` (neu), `src/Install/ContentSeeder.php`, `migrations/20261103100017_telc_german_exams.php` (neu).

**Nach dem Hochladen nötig:** `migrate` ausführen.

**Geprüft:** 116 automatische Tests grün.

## Punkt 2 (Teil): Google Maps statt OpenStreetMap

**Was neu ist**
- Auf jeder Standortseite steht jetzt eine **Google-Maps-Karte** (vorher OpenStreetMap). Sie lädt erst nach Klick auf „Karte laden“ oder automatisch, wenn „Externe Medien“ im Cookie-Banner erlaubt wurde. Vorher wird nichts an Google übertragen.
- Der Hinweis im Kartenfeld nennt Google als Anbieter und verlinkt auf den Abschnitt „Karten“ der Datenschutzerklärung.
- „Route planen“ öffnet die Routenplanung von Google Maps (nur ein Link, keine Datenübertragung beim Seitenaufruf).
- Sicherheitsrichtlinie (CSP): Nur noch `https://www.google.com` ist als Karteneinbettung erlaubt; OpenStreetMap ist entfernt. Der Kartenlader akzeptiert nur Google-Maps-Adressen.

**Für Nuna:** Der Link, den du geschickt hast, ist eine Google-Suche und kann sich ändern. Besser: in Google Maps euer Profil öffnen → „Teilen“ → „Link kopieren“ und diesen unter Verwaltung → Firmendaten & SEO → Google-Bewertungen eintragen, zusammen mit Sternen, Anzahl und Datum.

**Dateien:** `templates/public/location.twig`, `templates/partials/location_card.twig`, `public/assets/js/app.js`, `src/Core/App.php`, `src/Module/Consent/ConsentConfig.php`.

**Geprüft:** Karte im Browser geladen (Google-Einbettung, keine CSP-Fehler), 116 automatische Tests grün.

## Punkt 1: Datenschutzerklärung (Entwurf, muss fachlich geprüft werden)

Grundlage: Firmendaten von der bisherigen Datenschutzseite (meabildungszentrum.de/datenschutz) und die Funktionen, die die neue Website tatsächlich nutzt. Keine Analyse-, Marketing- oder Videodienste, weil derzeit keine genutzt werden.

**Inhalt (18 Abschnitte):** Verantwortlicher, Hosting bei netcup und Server-Logdateien, Schutz vor Angriffen (gekürzte IP, 90 Tage), Verschlüsselung, Cookies („mea“, „mea_consent“) und Einwilligungsnachweis, Schriften vom eigenen Server, Kontaktformular (Anonymisierung nach 12 Monaten), Anmeldung zu telc-Prüfungen (Weitergabe an telc, Aufbewahrung), PayPal, E-Mails (Protokoll 180 Tage), Google Maps (Sprungmarke `#karten`, nur nach Klick oder Einwilligung), Links zu Google, WhatsApp, Facebook und Instagram, Analyse- und Marketingdienste (derzeit keine), Bewerbungen, Empfänger und Drittländer, Rechte inklusive Widerspruch, Beschwerde bei der LDI NRW, Pflicht zur Bereitstellung, Änderungen. Die Speicherfristen entsprechen den Einstellungen der Website.

**Technisch**
- Der Text ersetzt nur den ursprünglichen Platzhalter. Wurde die Seite in der Verwaltung schon bearbeitet, bleibt sie unverändert.
- Überschriften im Seiteneditor dürfen jetzt eine Sprungmarke (`id`) haben, damit Links wie `/datenschutz/#karten` funktionieren. Andere Elemente bekommen weiterhin keine `id`.
- Keine E-Mail-Adresse im Klartext; Kontakt über Formular, Telefon und Post.

**Bitte vor dem Livegang von einer fachkundigen Person prüfen lassen**, besonders:
1. Datenschutzbeauftragter: Die alte Seite nennt keinen. Ob ihr einen benennen müsst (z. B. ab 20 Personen, die regelmäßig mit personenbezogenen Daten arbeiten), bitte klären.
2. Vertrag zur Auftragsverarbeitung mit netcup: abschließen bzw. bestätigen (im netcup-Kundenbereich möglich).
3. Weitergabe an telc: welche Angaben genau übermittelt werden.
4. Bewerbungen: Löschfrist von sechs Monaten so einhalten.
5. Werden später Google Analytics, Meta-Pixel oder YouTube eingeschaltet, muss die Datenschutzerklärung vorher ergänzt werden.

**Dateien:** `src/Install/PrivacyPolicy.php` (neu), `migrations/20261103100018_privacy_policy.php` (neu), `src/Install/ContentSeeder.php`, `src/Core/HtmlSanitizer.php`, `tests/Integration/PrivacyPolicyTest.php` (neu), `tests/Integration/MigrationTest.php`.

**Nach dem Hochladen nötig:** `migrate` ausführen.

**Geprüft:** 118 automatische Tests grün (2 neue), Seite im Browser angesehen, Barrierefreiheit (axe) ohne Befund.

---

# Zweite Runde (Wünsche vom 30.10.2026 abends)

## 1. Startseite: Leistungen zuerst
- Neue Reihenfolge: Startbereich → Zulassungen und Partner → **Leistungen** → Warum MEA → Prüfungen → nächste Termine → vier Schritte → Stimmen → Fragen → Kontakt.
- Die Schaltflächen im Startbereich lauten jetzt „Unsere Leistungen“ (Hauptschaltfläche) und „Prüfungstermine“.

## 2. Menüpunkt „Startseite“
- Steht jetzt an erster Stelle im Hauptmenü (auf dem Handy im Menü). In der Verwaltung unter Inhalte → Menüs änderbar.

## 3. Logo auf dem Anmeldeformular
- Die Anmeldeseite der Verwaltung (und „Passwort vergessen“, Zwei-Faktor usw.) zeigt jetzt das MEA-Logo aus den Firmendaten.

## 4. Zulassungen und Partner
- Logos stehen jetzt in gleich großen Kacheln mit Namen darunter, auf weißem Hintergrund mit Trennlinie und Überschrift. 6 nebeneinander am Computer, 3 auf dem Tablet, 2 auf dem Handy.

## 5. Google-Bewertungen und Stimmen
- **Startbereich:** Zeile mit Google-Logo, Sternen, „4,8 von 5 · 37 Google-Bewertungen“, verlinkt zum Google-Profil. Erscheint nur, wenn ein Profil-Link eingetragen ist.
- **Fußbereich:** Modul (weiße Karte) mit Google-Logo, großer Note, Sternen, Anzahl, Stand und Link „Bewertungen ansehen“. Daneben eine Stimme von Teilnehmenden.
- **Pflege:** Verwaltung → Firmendaten & SEO → Google-Bewertungen (Link, Note, Anzahl, Stand). Beim Seitenaufruf wird nichts an Google übertragen; das Logo ist auf dem eigenen Server.
- **Die drei Stimmen von Teilnehmenden:** in der Liste Inhalte → Bewertungen gibt es jetzt je Zeile „Ausblenden“ bzw. „Anzeigen“ (ein Klick, wird protokolliert).

## 6. Bewerbungsformular mit Upload
- Neue Seite **/karriere/bewerbung/**, verlinkt auf der Karriereseite (Text und Schaltfläche „Jetzt online bewerben“).
- Felder: Stelle (Jobcoach / Dozent:in, Sprachdozent:in, Initiativbewerbung), Vorname, Nachname, E-Mail, Telefon (optional), Unterlagen, Nachricht (optional), Datenschutz-Bestätigung.
- **Unterlagen:** nur PDF (Inhalt wird geprüft, nicht nur die Endung), höchstens 3 Dateien mit je 5 MB.
- **Sicherheit:** Dateien liegen außerhalb des öffentlichen Ordners und werden verschlüsselt gespeichert (libsodium, Schlüssel aus APP_KEY). Spam-Schutz wie beim Kontaktformular. Das Büro bekommt eine E-Mail **ohne Anhänge**.
- **Verwaltung → Kontakt → Bewerbungen:** Liste mit Filter, Detailansicht, Unterlagen herunterladen, „Abschließen“, „Wieder öffnen“, „Löschen“. Nur Administratoren haben Zugriff (neues Recht „Bewerbungen und Unterlagen“). Jeder Download wird protokolliert.
- **Löschfristen:** abgeschlossene Bewerbungen werden 6 Monate nach Abschluss automatisch mit allen Unterlagen gelöscht, nie abgeschlossene nach 12 Monaten (nächtliche Aufgabe, braucht den Cron-Job).
- Datenschutzerklärung, Abschnitt 13, ist entsprechend ergänzt (nur wenn der Text noch der ursprünglichen Fassung entspricht).
- Sicherungen enthalten die Bewerbungsunterlagen bewusst nicht (Löschpflicht).

**Wichtig für den Server:** In Plesk → PHP-Einstellungen muss `upload_max_filesize` mindestens **8M** und `post_max_size` mindestens **20M** sein. Sonst lehnt der Server größere PDFs ab (die Seite zeigt dann einen verständlichen Hinweis).

## 7. Kein E-Mail-Programm
- Auf der Kontaktseite gibt es keinen E-Mail-Link mehr, der ein Mailprogramm öffnet. Kontakt nur über Formular, Telefon und WhatsApp. Der zugehörige Programmcode ist entfernt.

## 8. „Direkter Draht“ → „Kontakt“
- Die Überschrift im Kasten auf der Kontaktseite heißt jetzt „Kontakt“.

**Dateien (1–8):** `templates/public/home.twig`, `templates/public/contact.twig`, `templates/public/page.twig`, `templates/public/application.twig` (neu), `templates/partials/footer.twig`, `templates/partials/google_rating.twig`, `templates/partials/google_g.twig` (neu), `templates/admin/layout.twig`, `templates/admin/crud/index.twig`, `templates/admin/applications/*` (neu), `templates/_icons.svg`, `public/assets/css/app.css`, `public/assets/css/admin.css`, `public/assets/js/app.js`, `src/Core/Request.php`, `src/Core/App.php`, `src/Admin/AdminModule.php`, `src/Admin/ReviewAdminController.php` (neu), `src/Admin/ApplicationAdminController.php` (neu), `src/Admin/Resources/ReviewResource.php`, `src/Module/Applications/*` (neu), `src/Module/Auth/Roles.php`, `src/Module/Ops/Scheduler.php`, `src/Install/ContentSeeder.php`, `src/Install/EmailTemplateSeeder.php`, `src/Install/PrivacyPolicy.php`, `migrations/20261103100019_menu_home_item.php` (neu), `migrations/20261103100020_create_applications.php` (neu), Tests `ApplicationTest` (neu), `GoogleRatingTest`.

**Nach dem Hochladen nötig:** `migrate` ausführen, PHP-Upload-Grenzen in Plesk prüfen.

# Update 0.7.2

## Startseite: kürzere Vorteile im Startbereich
- Unter den drei Punkten im Startbereich (telc-Prüfungszentrum, AZAV-Träger, Vor Ort im Ruhrgebiet) steht nur noch die Überschrift. Die Erklärzeilen darunter sind entfernt.

## Verwaltung: Menü als Akkordeon
- Die Gruppen im Seitenmenü (Prüfungen, Kontakt, Inhalte, Einstellungen, System) lassen sich auf- und zuklappen. Es ist immer nur eine Gruppe offen, standardmäßig die Gruppe der aktuellen Seite.
- Die lange Gruppe „System“ ist geteilt: „Einstellungen“ (Firmendaten & SEO, E-Mail-Vorlagen, E-Mail-Protokoll, Benutzer) und „System“ (Protokolle, Status, Sicherheit, Sicherungen, Updates, Systemprüfung).
- Das Dashboard steht als einzelner Link ganz oben.

**Dateien:** `templates/public/home.twig`, `templates/admin/layout.twig`, `public/assets/css/admin.css`, `public/assets/js/admin.js`, Test `AdminSecurityTest` (Anzahl E-Mail-Vorlagen auf 13 korrigiert).

# Update 0.7.3

## Startseite: Zitat-Karte im Startbereich entfernt
- Die Karte „Eine großartige Erfahrung! … Michael D., Fachlagerist“ rechts im Startbereich erscheint nicht mehr. Die eigenen Stimmen gibt es weiter auf „Über uns“; der Schalter „Im oberen Bereich der Startseite zeigen“ entfällt.

## Google-Bewertungen aus dem Unternehmensprofil
- Neue Seite in der Verwaltung: Inhalte → **Google-Bewertungen**. Dort stehen Profil-Link, Gesamtwert (Sterne, Anzahl, Stand) und die einzelnen Bewertungen. Jede Bewertung lässt sich mit einem Klick ausblenden oder wieder anzeigen, Bewertungen lassen sich auch von Hand anlegen.
- **Automatischer Abruf:** Mit einem Google API-Schlüssel (Places API) holt die Website jede Nacht Gesamtwert, Anzahl und die bis zu fünf Bewertungen, die Google liefert. Knopf „Jetzt von Google abrufen“ für sofort. Der Schlüssel wird verschlüsselt gespeichert. Besucher laden dabei nichts von Google.
- **Startseite:** Sterne mit Gesamtwert und Anzahl unter den Knöpfen im Startbereich.
- **Fußbereich jeder Seite:** Karte mit Gesamtwert und daneben die drei neuesten sichtbaren Google-Bewertungen (ohne Google-Bewertungen wie bisher eine eigene Stimme).
- Der Profil-Link zum MEA-Profil wird beim Update eingetragen, falls noch keiner gesetzt war. Die Google-Felder sind aus „Firmendaten & SEO“ auf die neue Seite umgezogen.
- Im Menü heißt der bisherige Punkt „Bewertungen“ jetzt „Stimmen (eigene)“.

**Dateien:** `migrations/20261104100021_create_google_reviews.php` (neu), `src/Module/Content/GoogleReviewSync.php` (neu), `src/Admin/GoogleReviewAdminController.php` (neu), `src/Admin/Resources/GoogleReviewResource.php` (neu), `templates/admin/google_reviews_panel.twig` (neu), `src/Admin/AdminModule.php`, `src/Admin/SettingsController.php`, `src/Admin/Resources/ReviewResource.php`, `src/Core/App.php`, `src/Module/Content/ContentRepository.php`, `src/Module/Content/PublicController.php`, `src/Module/Ops/Scheduler.php`, `templates/public/home.twig`, `templates/partials/footer.twig`, `templates/admin/layout.twig`, `public/assets/css/app.css`, `public/assets/css/admin.css`, Test `GoogleReviewSyncTest` (neu).

**Nach dem Einspielen:** nichts weiter nötig (die Datenbank wird vom Update selbst aktualisiert). Für den automatischen Abruf den API-Schlüssel eintragen.

# Update 0.7.4

- Google-Sterne im Startbereich: kompakter und halb durchsichtig, nur noch „4,9 (10)“ ohne den Text „Google-Bewertungen“ (für Screenreader bleibt er erhalten).
- Google-Karte im Fußbereich: halb durchsichtig statt weiß, passend zu den Bewertungskarten daneben.

**Dateien:** `templates/partials/google_rating.twig`, `public/assets/css/app.css`.

# Update 0.7.5

## Updates direkt in der Verwaltung
- **Hochladen ohne Größenproblem:** Das Paket wird im Browser in kleine Teile zerlegt und Stück für Stück hochgeladen. Die Upload-Grenzen des Servers spielen keine Rolle mehr.
- **Installation ohne Abbruch:** Nach „Update jetzt installieren“ öffnet sich eine Fortschrittsseite. Die Installation läuft getrennt davon im Hintergrund, entweder im minütlichen Cron oder direkt nach dem Klick. Die 60-Sekunden-Grenze des Webservers stört nicht mehr. Die Seite zeigt Schritt für Schritt, was passiert, und am Ende „installiert“ oder den Fehler (dann ist automatisch der alte Stand wieder da).
- Fehlt die Identitätsbestätigung, wird das Paket nicht mehr gelöscht: nach der Bestätigung geht es direkt beim geprüften Paket weiter.
- Die Kommandozeile `update:install` funktioniert weiterhin.

**Dateien:** `src/Admin/UpdateController.php`, `src/Module/Ops/UpdateJobs.php` (neu), `src/Module/Ops/UpdateService.php`, `src/Module/Ops/Scheduler.php`, `src/Core/Response.php`, `templates/admin/updates/index.twig`, `templates/admin/updates/progress.twig` (neu), `public/assets/js/admin.js`, `public/assets/css/admin.css`, Tests `UpdateAdminTest` (neu), `OpsTest`.

# Update 0.7.6

- Startseite, „Zulassungen und Partner“: mehr Kontrast. Der Bereich hat einen leicht getönten Hintergrund, die weißen Kästen einen kräftigeren Rand und Schatten, die Namen sind dunkler.

**Dateien:** `public/assets/css/app.css`.

# Update 0.8.0 – Designsystem in der Verwaltung

Das Aussehen der ganzen Website lässt sich jetzt in der Verwaltung einstellen: **Einstellungen → Designsystem** (nur Administratoren). Es müssen keine CSS-Dateien mehr bearbeitet werden. Mit den Standardwerten sieht die Website aus wie bisher.

## Die Seite „Designsystem“
- **Fünf Reiter:** Typografie (Schriftfamilien, Größen am Computer, Gewicht, Zeilenhöhe, Buchstabenabstand, Großschreibung, kursiv), Farben (Primär-, Sekundär-, Akzentfarbe, Hintergründe, Text, Links, Buttons mit Hover/Klick/Fokus, Rahmen, Erfolg/Warnung/Fehler), Layout (Inhaltsbreite, Seitenrand, Sektionsabstand, Abstandsfaktor, Abstände zwischen Überschrift, Text und Elementen), Komponenten (Buttons, Karten, Formulare, Eckradien, Rahmenstärken, Schatten), Responsive (Werte für Handys, Tablet wird stufenlos dazwischen berechnet).
- **Farbwähler und HEX-Feld** nebeneinander, beide bleiben gleich.
- **Live-Vorschau** rechts: die echte Website (Startseite, Leistungen, Prüfungsübersicht, eine Prüfungsseite, Standorte, Kontakt, FAQ) als Computer, Tablet oder Handy. Jede Änderung erscheint sofort, gespeichert wird erst mit „Speichern“.
- Jedes Feld zeigt den **Standardwert** und hat einen Knopf „Standard“. Geänderte Felder sind markiert, oben steht, wie viele Werte geändert sind.
- **Prüfung beim Tippen und beim Speichern:** Fehler stehen direkt am Feld und am Reiter. Hinweise zur Lesbarkeit, wenn ein Farbpaar zu wenig Kontrast hat (Speichern bleibt möglich).
- **„Alles auf Standard zurücksetzen“** mit Rückfrage.
- **Eigene Schriften:** WOFF2- oder WOFF-Datei hochladen (max. 2 MB), danach in allen Schriftfeldern wählbar. Eine Schrift, die verwendet wird, lässt sich nicht löschen.
- **Mitgelieferte Schriften:** zusätzlich zu Plus Jakarta Sans jetzt Inter, Open Sans, Source Sans 3, Nunito, Lora, Source Serif 4, Merriweather und zwei Systemschriften. Alle liegen auf dem eigenen Server; nichts wird von Google geladen (Datenschutz).

## Technik
- Standardwerte stehen im Programm, in der Datenbank nur Abweichungen (neue Tabellen `design_tokens`, `design_fonts`).
- Die Werte gehen als **CSS-Variablen** an die Website (`--color-primary`, `--font-size-h1`, `--radius-card` …). Die Datei `public/media/design/design-<prüfsumme>.css` entsteht bei jedem Speichern neu; Browser dürfen sie ein Jahr lang zwischenspeichern, weil sich der Name mit jedem Inhalt ändert. Kein zusätzlicher Datenbankzugriff pro Seitenaufruf.
- `app.css` ist komplett auf die Variablen umgestellt: keine festen Farben, Schriftnamen, Schriftgrößen oder -gewichte mehr. Doppelte und sich widersprechende Regeln sind zusammengeführt. Ein Test passt darauf auf.
- Nebenbei behoben: Auf dem Handy stand im Seitenkopf der Unterseiten das Bild neben dem Text statt darunter (eine doppelte Regel hat die Handy-Ansicht überschrieben).
- Sicherheit: kein Feld nimmt CSS-Text an; Farben nur HEX, Zahlen nur im erlaubten Bereich, alles andere nur aus festen Listen. Neues Recht „Designsystem“, CSRF-Schutz, jede Änderung im Änderungsprotokoll. Schriftdateien werden am Inhalt erkannt.
- Die Vorschau darf die eigene Website in einem Rahmen zeigen (nur mit `?designvorschau=1`, nur von derselben Adresse). Sonst bleibt das Einbetten verboten.

**Dateien:** `src/Module/Design/*` (neu), `src/Admin/DesignController.php` (neu), `templates/admin/design/edit.twig` (neu), `templates/partials/design_head.twig` (neu), `migrations/20261105100022_create_design_system.php` (neu), `public/assets/css/app.css` (neu aufgebaut), `public/assets/fonts/*` (7 Schriften neu), `public/assets/css/admin.css`, `public/assets/js/admin.js`, `templates/admin/layout.twig`, `templates/admin/_icons.svg`, `templates/layout/base.twig`, `templates/public/offline.twig`, `src/Core/App.php`, `src/Core/View.php`, `src/Admin/AdminModule.php`, `src/Module/Auth/Roles.php`, `src/Module/Security/FileGuard.php`, `DESIGN.md`, `docs/betrieb.md`, `docs/adr/README.md`, Tests `DesignSystemTest`, `DesignSystemUnitTest`, `DesignCssUsageTest` (neu).

**Nach dem Einspielen:** nichts weiter nötig. Das Update führt die Datenbankschritte selbst aus; die CSS-Datei entsteht beim ersten Seitenaufruf.

# Update 0.9.4 – Partnerlogos (31.10.2026)

- Partnerlogos ohne sichtbare Bezeichnung: nur das Logo, groß und mittig in einer hellen Karte mit festem Seitenverhältnis 3:2 (Computer 6 nebeneinander, Tablet 3, Handy 2).
- Der Name bleibt als Alternativtext des Bildes (für Screenreader und Suchmaschinen). Im Seitenbaukasten heißt das Feld jetzt „Name (nur für Screenreader und Suchmaschinen)“.
- Weiße Logo-Hintergründe gehen in der Kartenfarbe auf (`mix-blend-mode: multiply`).

**Dateien:** `templates/blocks/partners.twig`, `public/assets/css/app.css`, `src/Module/Builder/BlockTypes.php`.

**Geprüft:** Ansicht bei 1920 und 375 px, Barrierefreiheitsprüfung der Seiten (smoke.js) ohne Befund, 178 Tests bestanden.

# Update 0.9.3 – Vollständige Sicherung (31.10.2026)

**Anlass:** Die Sicherung enthielt nur die Datenbank, die Originalbilder (`storage/uploads`) und die Medien (`public/media`). Programmdateien, `.env` und sogar die Bewerbungsunterlagen (`storage/applications`) fehlten.

**Was neu ist**
- Jede Sicherung enthält die Datenbank und **alle Dateien des Projektordners**. Ausgenommen sind nur `storage/backups` (die Sicherungen selbst), `storage/tmp`, `storage/cache`, `storage/updates` (Rücksprung-Kopien früherer Updates) und Git-Verwaltungsdaten (`.git`, `node_modules`).
- Neue Datei `LIESMICH.txt` in jeder Sicherung mit Inhalt und Anleitung für den Umzug auf einen neuen Server.
- Wiederherstellen in der Verwaltung spielt Datenbank, Originalbilder, Medien und jetzt auch Bewerbungsunterlagen zurück. Programmdateien werden bewusst nicht zurückgespielt, damit Programm und Datenbank zusammenpassen; sie stecken für den Serverumzug in der Sicherung.
- Ältere Sicherungen (Format 1) bleiben gültig und einspielbar.
- Speicherplatz wird vorher geprüft (etwa doppelte Größe des Projektordners frei).

**Dateien:** `src/Module/Ops/BackupService.php`, `src/Admin/BackupController.php`, `templates/admin/backups/{index,zip,restore}.twig`, `docs/betrieb.md`, `tests/Integration/OpsTest.php`.

**Geprüft:** `OpsTest` (Inhalt vollständig, Ausnahmen fehlen, Bewerbungen werden zurückgespielt, Programmdateien nicht). Auf einer Testkopie mit 0.9.3: Sicherung 20,6 MB in 4 Sekunden, Wiederherstellung einer neuen und einer alten Sicherung erfolgreich, ZIP mit Passwort mit 7-Zip geprüft (7876 Dateien). Alle 178 Tests bestanden.

**Einschränkung:** Gesichert wird der Projektordner der Website. Andere Ordner auf dem Server (z. B. andere Webseiten im selben Paket, E-Mail-Postfächer, Serverkonfiguration) sind nicht enthalten; dafür ist die Datensicherung des Hosters zuständig.

# Update 0.9.2 – Sicherung als ZIP mit Passwort (31.10.2026)

**Anlass:** Sicherungen wurden nur als verschlüsselte `.meabak`-Datei heruntergeladen, die sich nicht mit einem Entpackprogramm öffnen lässt.

**Was neu ist**
- Verwaltung → Sicherungen: Knopf „Als ZIP“ je Sicherung. Nach der Identitätsbestätigung vergeben Sie ein Passwort (mind. 12 Zeichen, zweimal eingeben). Jede Datei in der ZIP ist damit per AES-256 verschlüsselt.
- Öffnen mit 7-Zip, WinZip oder Keka. Das in Windows eingebaute Entpacken unterstützt AES nicht.
- „Sicherung hochladen“ nimmt jetzt auch diese ZIP an (mit Passwort). Sie wird geprüft und als normale Sicherung übernommen.
- Das Passwort wird nicht gespeichert. Die ZIP wird nur für den Download erzeugt und danach gelöscht. Der Download steht im Änderungsprotokoll und zählt als „extern gespeichert“.
- Der bisherige Download heißt jetzt „.meabak“ und bleibt unverändert.

**Dateien:** `src/Module/Ops/BackupService.php`, `src/Admin/BackupController.php`, `src/Core/Response.php` (Aufräumen nach einem Datei-Download), `templates/admin/backups/zip.twig` (neu), `templates/admin/backups/index.twig`, `docs/betrieb.md`, `tests/Integration/OpsTest.php`.

**Geprüft:** Test `OpsTest::testBackupZipWithOwnPasswordRoundTrip` (AES-256 je Datei, falsches oder fehlendes Passwort abgelehnt, Wiedereinspielen). Im Browser heruntergeladen und mit 7-Zip geöffnet: 104 Dateien, Methode AES-256, falsches Passwort wird abgelehnt. Barrierefreiheitsprüfung (axe) der neuen Seite ohne Befund.

**Einschränkung:** Sehr große Sicherungen brauchen beim Download kurz den doppelten Speicherplatz in `storage/tmp`.

# Update 0.9.1 – Neues Startbild (31.10.2026)

**Was neu ist**
- Neues Foto „Gemeinsames Lernen“ im Startbereich (`migrations/media/hero-gemeinsames-lernen.jpg`, 1672 × 941, daraus WebP/JPEG in 480, 960, 1440 und 1672 px).
- Übernahme beim ersten Aufruf nach dem Update über `ContentPatches` (einmalig, gemerkt in der Einstellung `content_patches`). Ist das Bild schon in den Medien (gleiche Prüfsumme), wird es nicht doppelt angelegt.
- Verlauf: dunkel nur hinter der Textspalte, die Position richtet sich nach dem Inhaltsrand. Links davon und rechts bleibt das Foto sichtbar.
- Drei Vorteile: 13 px Schrift, eine Zeile ab Tablet-Breite, auf dem Handy untereinander.

**Dateien:** `public/assets/css/app.css`, `src/Module/Builder/ContentPatches.php` (neu), `src/Core/App.php`, `migrations/media/hero-gemeinsames-lernen.jpg` (neu), `tests/Integration/PageBuilderTest.php`.

**Hinweis:** Wer das Startbild danach im Seitenbaukasten ändert, behält seine Wahl. Der Schritt läuft nur einmal.

# Update 0.9.0 – Seitenbaukasten

Ausführlich mit Ursache, Bestandsaufnahme, Tests und Einschränkungen: `docs/pagebuilder.md`.

## Startseite in der Seitenverwaltung
Die Startseite war kein Eintrag in der Seitentabelle, sondern ein festes Template mit Werten aus den Einstellungen. Sie ist jetzt eine geschützte Seite „Startseite“ (Adresse `/`, nicht löschbar), steht oben in der Seitenverwaltung und ist komplett bearbeitbar. Die alten Startseiten-Felder unter Einstellungen entfallen.

## „Warum MEA“
Foto oben ausgerichtet, die vier Punkte als Karten in einer Reihe unter Text und Foto.

## Seitenbaukasten
Visueller Editor für alle Inhaltsseiten: 40 Elemente, Ziehen und Ablegen, direktes Schreiben in der Seite, Stil je Element für Computer, Tablet und Handy, Struktur-Baum, Rückgängig/Wiederholen, Entwurf, Vorschau, Veröffentlichen, Versionen, Konflikterkennung, globale Bausteine, Bildauswahl mit Suche und Hochladen. Alle 12 Seiten wurden übernommen; Adressen und SEO bleiben gleich.

## Kopf- und Fußbereich
Neue Seite in der Verwaltung für Button-Texte, Überschriften und Links in Kopf, Fuß und Schnellleiste.

## Rechte
Neue Rolle „Autor/in“ (Entwürfe, keine Veröffentlichung). Neues Recht „Veröffentlichen“ für Administrator und Redaktion.

**Nach dem Hochladen:** nichts weiter nötig; die Updatefunktion führt die Migration aus und übernimmt die Seiten.
