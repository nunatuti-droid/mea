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
