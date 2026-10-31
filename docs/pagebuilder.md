# Seitenbaukasten (visueller Pagebuilder), Version 0.9.0

Stand: 31.10.2026. Dieses Dokument beschreibt Ursache, Umsetzung, Prüfung und Grenzen. Was nicht umgesetzt oder nicht geprüft ist, steht ausdrücklich unter „Einschränkungen“.

## 1. Ursache: Startseite fehlte in der Verwaltung

- Die Startseite `/` wurde von `PublicController::home()` aus dem festen Template `templates/public/home.twig` erzeugt.
- Ihre Texte kamen aus der Einstellung `home` (Verwaltung → Einstellungen → Gruppe „Startseite“) und `seo_home`.
- Sie war **nie ein Datensatz in der Tabelle `pages`**. Die Seitenverwaltung ist eine Liste über genau diese Tabelle, deshalb fehlte die Startseite dort.
- Die übrigen Seiten standen zwar in `pages`, aber nur Titel, Einleitung und ein Textfeld waren bearbeitbar. Alles andere (Listen, Formulare, Schritte, Aufrufe) stand fest in eigenen Templates.

**Behebung:** Die Migration `20261106100023_create_page_builder` legt die Startseite als normale Seite an (`slug = startseite`, `is_home = 1`, Systemseite). Die Spalte `is_home` ist eindeutig (nur eine Startseite möglich). SEO-Titel und -Beschreibung wurden aus `seo_home` übernommen. Die Übernahme (`LegacyImport`) prüft vorher, ob es schon eine Startseite gibt; ein zweiter Lauf legt nichts doppelt an (getestet). `/startseite/` leitet dauerhaft (301) auf `/` um, die Sitemap enthält `/` nur einmal.

## 2. Bestandsaufnahme

| Bereich | Frontend vorhanden | Im Backend sichtbar (vorher) | Bearbeitbar (vorher) | Maßnahme (umgesetzt in 0.9.0) |
|---|---|---|---|---|
| Startseite `/` | ja | nein | teilweise (feste Felder unter Einstellungen) | Seite „Startseite“ in der Seitenverwaltung, komplett im Seitenbaukasten |
| Leistungen (Übersicht) | ja | ja | nur Titel/Einleitung | Bausteine; Liste als Baustein „Leistungen (Karten)“ |
| telc-Prüfungszentrum | ja | ja | nur Text | Bausteine: Seitenkopf, Buttons, Prüfungsliste mit Filter, Schritte, Bild mit Text, FAQ, Aufruf |
| Preise | ja | ja | nur Einleitung | Bausteine inkl. „Preistabelle Prüfungen“ |
| Standorte (Übersicht) | ja | ja | nur Einleitung | Bausteine inkl. „Standorte“ |
| Kontakt | ja | ja | nur Einleitung | Bausteine „Kontaktformular“ und „Kontaktbereich“ |
| Häufige Fragen | ja | ja | nur Einleitung | Bausteine; Fragen weiter unter „FAQ“ gepflegt |
| Über uns, Karriere | ja | ja | Textfeld | Bausteine |
| Impressum, Datenschutz, AGB | ja | ja | Textfeld | Bausteine (Text) |
| Leistungsseiten (z. B. `/avgs/`) | ja | ja (Leistungen) | ja, Formular mit Vorschau | unverändert (Datensätze mit Formular) |
| Prüfungsseiten | ja | ja (Prüfungen) | ja, Formular | unverändert |
| Standortseiten | ja | ja (Standorte) | ja, Formular | unverändert |
| Kopfbereich: Logo, Menü | ja | ja (Firmendaten, Menüs) | ja | unverändert |
| Kopfbereich: Button „Prüfung buchen“ | ja | nein | nein | neu: Verwaltung → Kopf- und Fußbereich |
| Fußbereich: Texte, Überschriften, Links | ja | nein | nein | neu: Verwaltung → Kopf- und Fußbereich |
| Schnellleiste (Handy) | ja | nein | nein | neu: Verwaltung → Kopf- und Fußbereich |
| Wiederverwendbare Abschnitte | nein | nein | nein | neu: Globale Bausteine |
| Buchungsstrecke, Bewerbungsformular | ja | ja (Buchungen, Bewerbungen) | Daten ja, Texte fest | unverändert, siehe Einschränkungen |
| Fehlerseite 404, Wartungsseite | ja | Wartungsseite ja | Wartungstext ja | unverändert |

## 3. Bedienung

**Seitenverwaltung** (Inhalte → Seiten): alle Seiten mit Startseite ganz oben (grün markiert). Je Seite: Visuell bearbeiten, Einstellungen (Titel, Adresse, Status, SEO), Ansehen, Kopieren (als Entwurf), Löschen (nicht für Startseite und Systemseiten). Darunter Verweise auf Seiten aus Datensätzen und auf die globalen Bereiche.

**Editor** (`/verwaltung/seiten/<id>/editor/`):
- **Oben:** Zurück, Seitenwahl, Gerät (Computer, Tablet, Handy), Rückgängig/Wiederholen, Versionen, Seite & SEO, Vorschau, Speichern, Veröffentlichen, Anzeige „Ungespeicherte Änderungen“.
- **Links:** Elemente (Suche, Kategorien, globale Bausteine), Struktur (Baum mit Ziehen und Ablegen), Einstellungen (Inhalt, Stil je Gerät, Erweitert), Design (Farben und Umbruchpunkte aus dem Designsystem, Links zu den globalen Bereichen).
- **Mitte:** die echte Seite mit Kopf- und Fußbereich. Hover zeigt den Rahmen, Klick wählt aus, die Leiste am Element bietet Ziehen, übergeordnetes Element, nach oben/unten, duplizieren, ausblenden, löschen. Doppelklick auf Überschriften, Texte, Button-Beschriftungen und Karten-Titel bearbeitet direkt in der Seite.
- **Tastatur:** Strg+S speichern, Strg+Z / Strg+Umschalt+Z, Strg+D duplizieren, Entf löschen, Esc übergeordnetes Element, Alt+↑/↓ verschieben.
- Löschen von Abschnitten, Spalten, Gruppen und dynamischen Bausteinen fragt nach.
- Inhalte, die direkt auf Seitenebene abgelegt werden, kommen automatisch in einen Abschnitt.

**Speichern und Veröffentlichen:** „Speichern“ legt einen Entwurf an. Besucher sehen weiter den veröffentlichten Stand. „Vorschau“ zeigt den Entwurf (nur angemeldet, `noindex`). „Veröffentlichen“ (Recht „publish“) macht ihn sichtbar. Jeder Entwurf und jede Veröffentlichung wird als Version gespeichert (höchstens 60 je Seite). Unter „Versionen“ lässt sich eine Version in den Editor laden oder der Entwurf verwerfen.

**Gleichzeitiges Bearbeiten:** Speichert jemand anderes zwischendurch, erscheint „Seite wurde inzwischen geändert“ mit Name und der Wahl „Neu laden“ oder „Meinen Stand speichern“.

**Globale Bausteine** (Inhalte → Globale Bausteine): ein Element im Editor unter „Erweitert → Als globalen Baustein speichern“. Er erscheint lila markiert mit „Global“; Änderungen im Baustein wirken nach dem Veröffentlichen auf allen Seiten. „In lokale Kopie umwandeln“ löst die Verknüpfung. Löschen nur, wenn er nirgends verwendet wird und nicht geschützt ist.

**Kopf- und Fußbereich** (Inhalte → Kopf- und Fußbereich): Texte und Links der Bereiche auf allen Seiten. Leer = Standardtext.

**Bilder:** Auswahl mit Suche, Hochladen mit Pflicht-Alternativtext, Alternativtext direkt ändern. Bilder in der Mediathek zeigen jetzt auch, auf welchen Seiten (veröffentlicht oder Entwurf) sie im Seitenbaukasten verwendet werden.

## 4. Elemente (40 in der Auswahl, dazu Spalte und Verweis auf globale Bausteine)

- **Struktur:** Abschnitt, Spalten (8 Aufteilungen, Umbruch ab Tablet/Handy/nie, Reihenfolge umkehren), Gruppe (Flex: neben-/untereinander, Ausrichtung, Verteilung, Umbruch, als Karte), Abstand, Trennlinie.
- **Grundelemente:** Überschrift (H1–H6, Größe getrennt von der Ebene), kleine Zeile, Text (fett, kursiv, Link, Listen, Zwischenüberschrift), Button-Gruppe, Button, Symbol, Aufzählung, Linkliste, Tabelle.
- **Bilder und Medien:** Bild, Bild mit Text, Bildergalerie, Video (YouTube ohne Cookies, Vimeo; lädt erst nach Klick).
- **Inhaltsbausteine:** Abschnittskopf, Karten und Kacheln, Akkordeon, Tabs, Kennzahlen, Kundenstimmen, Handlungsaufruf, Downloads, Kontaktbereich, Schritte.
- **MEA-Bausteine mit Daten der Verwaltung:** Startbereich, Seitenkopf, Partnerlogos, Leistungen, Prüfungen (Karten), Prüfungsliste mit Filter, nächste Termine, Google-Bewertung, Häufige Fragen, Standorte, Preistabelle, Kontaktformular (einmal je Seite), Hinweis Prüfungsort.

**Stil je Element und Gerät:** Schriftfamilie, -größe, -stärke, Zeilenhöhe, Buchstabenabstand, Schreibweise, Stil, Ausrichtung; Schrift- und Hintergrundfarbe (Farben des Designsystems oder eigene), Deckkraft; Innen- und Außenabstände; Breite, maximale Breite, Höhe, Mindesthöhe, horizontale Lage; Rahmen, Eckradius, Schatten; Abstand, Richtung, Ausrichtung und Spaltenzahl für Layout-Elemente; ausblenden je Gerät. Leere Felder nutzen das Designsystem; eigene Werte sind als „eigener Wert“ markiert und einzeln oder gesammelt zurücksetzbar. Tablet und Handy übernehmen den Wert darüber („geerbt“).

## 5. Technik

- **Datenmodell:** `pages.blocks_json` (veröffentlicht) und `pages.draft_json` (Entwurf), `draft_rev` für die Konflikterkennung, `draft_by`, `draft_updated_at`, `css_hash`, `is_home` (eindeutig). Neue Tabelle `builder_components` für globale Bausteine. Versionen in `content_revisions` (`page_builder`, `component`). Neue Rolle „Autor/in“ (Entwürfe), neues Recht „publish“ (Administrator, Redaktion).
- **Ein Darstellungsweg:** Website, Vorschau und Editor nutzen denselben `PageRenderer` und dieselben Templates `templates/blocks/*.twig`. Der Editor ergänzt nur `data-b`-Markierungen.
- **CSS:** Stile je Element werden geprüft und als Datei `public/media/design/seite-<id>-<hash>.css` geschrieben (Content-Security-Policy erlaubt keine Inline-Stile). Umbruchpunkte kommen aus dem Designsystem (neu: Tablet 900 px, Handy 600 px). Speichern im Designsystem erzeugt die Seiten-CSS neu.
- **Prüfung auf dem Server (`TreeValidator`):** erlaubte Verschachtelung, höchstens 1500 Elemente und 8 Ebenen, Feldtypen, Längen, Auswahllisten, Zahlenbereiche, Symbole, sichere Links (kein `javascript:`, `data:`, `//fremd`), HTML über den vorhandenen `HtmlSanitizer`, vorhandene Medien und Bausteine, Stilwerte nur aus festen Bereichen. Unzulässiges wird entfernt und gemeldet.
- **Sicherheit:** Rechte und CSRF-Token in jeder Verwaltungsanfrage; Arbeitsbereich nur unter `/verwaltung/…/leinwand/` und nur von derselben Website einbettbar (`frame-ancestors 'self'`), alle anderen Seiten weiter `DENY`; Änderungsprotokoll für Speichern, Veröffentlichen, Verwerfen, Kopieren und globale Bereiche.
- **Dateien:** `src/Module/Builder/*`, `src/Admin/PagesController.php`, `src/Admin/TemplateTextsController.php`, `src/Module/Content/TemplateTexts.php`, `templates/blocks/*`, `templates/admin/builder/*`, `templates/admin/texts.twig`, `public/assets/js/builder.js`, `public/assets/css/builder*.css`, Migration `20261106100023_create_page_builder.php`.

## 6. Übernahme der Inhalte

Alle 12 Seiten (Startseite, Leistungen, telc-Prüfungszentrum, Preise, Standorte, Kontakt, Häufige Fragen, Über uns, Karriere, Impressum, Datenschutz, AGB) wurden automatisch in Bausteine umgewandelt. Adressen und SEO-Angaben bleiben gleich. Die alten Templates der Seiten wurden entfernt. Vor dem Update legt die Updatefunktion wie immer eine Sicherung an.

Vergleich alt/neu mit Bildschirmfotos aller 10 Hauptseiten bei 1366 px und 375 px: 18 von 20 pixelgleich. Startseite: Unterschied nur durch das verzögerte Laden von Bildern im alten Bildschirmfoto. Karriere: 16 px kürzer, weil ein Button jetzt den einheitlichen Abstand nutzt.

## 7. Tests

- 175 automatische Tests grün, davon 12 neu in `tests/Integration/PageBuilderTest.php`: Startseite einmalig, oben gelistet und geschützt; alle Seiten übernommen, Adressen gleich, genau eine H1, keine Editor-Markierungen; Entwurf unsichtbar bis Veröffentlichung, Vorschau nur angemeldet; Versionen; Konflikt (409) und bewusstes Überschreiben; XSS und unsichere Links/Stile werden entfernt; Rechte (Autor/in, Buchung) und CSRF; Einbettung nur des Arbeitsbereichs; globale Bausteine; Kopf- und Fußbereich mit Prüfung der Links; Mediennutzung; neue Seite und Kopie; Stile je Gerät.
- Browser (Playwright): Auswählen, Stil ändern, Direkt-Schreiben, Ziehen aus der Elementliste, Rückgängig/Wiederholen, Tablet-Ansicht, Speichern, Konflikt in zwei Tabs, Seite & SEO, Bildauswahl, globalen Baustein anlegen, Veröffentlichen, Ergebnis auf der Website.
- Barrierefreiheit (axe-core, WCAG 2.2 AA): alle öffentlichen Seiten und alle Verwaltungsseiten inkl. Editor bei 1366 px und 375 px ohne Befund.

## 8. Einschränkungen und offene Punkte

- **Seiten aus Datensätzen** (einzelne Leistungen, Prüfungen, Standorte) laufen weiter über ihre Formulare und nicht über den Seitenbaukasten. Feste Beschriftungen in diesen Templates (z. B. „Termine“, „Jetzt anmelden“) sind noch nicht in der Verwaltung änderbar.
- **Buchungsstrecke, Bewerbungsformular, 404-Seite** sind nicht im Seitenbaukasten.
- **Archivieren:** kein eigener Status. Eine Seite lässt sich auf „Entwurf“ setzen (nicht mehr öffentlich) oder löschen.
- **Freie Positionierung** (absolut/fixiert) und frei definierte CSS-Grids gibt es bewusst nicht. Layouts entstehen über Abschnitt, Spalten (feste Aufteilungen) und Gruppe (Flexbox), damit alles auf jedem Gerät funktioniert.
- **Direkt-Schreiben in der Seite** gibt es für Überschriften, kleine Zeilen, Texte, Button-Beschriftungen und Karten-Titel. Alle anderen Felder werden in der Seitenleiste bearbeitet.
- **Ziehen und Ablegen** funktioniert mit der Maus. Ohne Maus: Element anklicken bzw. Enter (fügt nach dem ausgewählten Element ein), verschieben mit Alt+↑/↓ oder den Pfeil-Buttons.
- **Rückgängig/Wiederholen** gilt für die laufende Sitzung im Editor; danach helfen die Versionen.
- **Gleichzeitiges Bearbeiten** wird beim Speichern erkannt; es gibt keine Live-Anzeige, wer gerade bearbeitet.
- **Editor auf dem Handy:** bedienbar, aber eng. Empfohlen ist ein Bildschirm ab etwa 1200 px Breite.
- **Textformatierung** nutzt `document.execCommand`. Das ist in allen aktuellen Browsern verfügbar, gilt aber als veraltet.
- **Hochladen in der Bildauswahl:** ein Alternativtext gilt für alle gleichzeitig hochgeladenen Bilder; danach einzeln änderbar.
- **PDFs** werden über Link-Felder mit Vorschlagsliste eingebunden, nicht über eine eigene Dokumentenauswahl.
- **Nicht geprüft:** Bedienung mit Screenreader durch eine Person, Safari und Firefox (getestet wurde mit Chromium), Last mit sehr großen Seiten (Grenze 1500 Elemente).
