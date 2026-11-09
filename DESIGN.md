# MEA Designsystem

Leitidee: **„Klarer Weg zum Ziel“.** Die Raute aus dem MEA-Logo ist das wiederkehrende Element:
kleine Rauten als Aufzählungszeichen und Eyebrow-Marker, große Rauten als ruhige Hintergrundformen,
Bildrahmen mit einer „spitzen“ Ecke (drei große Radien, eine kleine Ecke unten links).
Fotos der MEA-Standorte tragen die Seite, Grün steht für Handlung, Dunkelblau für Vertrauen.

Referenzen: die bestehende MEA-Website (Inhalte, Marke), perfekt-deutsch.de (Nutzerführung zur Prüfung),
Eduma (Kartenlogik, Abschnittsfolge). Es wurden keine Gestaltungselemente kopiert.

Alle Werte sind **in der Verwaltung unter Einstellungen → Designsystem** einstellbar und erreichen die Website als
CSS-Variablen. Komponenten (`public/assets/css/app.css`) verwenden nur diese Namen, nie Rohwerte. Ein Test
(`tests/Unit/DesignCssUsageTest.php`) schlägt fehl, sobald in `app.css` eine Farbe, ein Schriftname, eine feste Schriftgröße
oder ein festes Schriftgewicht steht oder eine Variable benutzt wird, die das Designsystem nicht kennt.

## So funktioniert das Designsystem

| Baustein | Datei | Aufgabe |
| --- | --- | --- |
| Felder und Standardwerte | `src/Module/Design/DesignSchema.php` | rund 110 Werte mit Bereich (min/max/Schritt), Bezeichnung, Reiter und CSS-Variable |
| Prüfung | `src/Module/Design/DesignValidator.php` | HEX-Farben, Zahlen im erlaubten Bereich, nur Werte aus festen Listen, Kontrast-Hinweise (WCAG) |
| Schriften | `src/Module/Design/FontLibrary.php` | 8 Webfonts lokal in `public/assets/fonts` (SIL OFL), 2 Systemschriften, eigene Uploads (WOFF2/WOFF) |
| CSS-Erzeugung | `src/Module/Design/DesignCss.php` | `:root { … }` und `@font-face`; stufenlose Größen mit `clamp()` |
| Speichern und Cache | `src/Module/Design/DesignService.php` | Abweichungen in `design_tokens`, Datei `public/media/design/design-<prüfsumme>.css` |
| Verwaltung | `src/Admin/DesignController.php`, `templates/admin/design/edit.twig` | fünf Reiter, Live-Vorschau, Speichern, Zurücksetzen, eigene Schriften |

- **Standard und Gespeichertes sind getrennt:** Standardwerte stehen nur im Code. In der Datenbank liegen nur Werte, die davon abweichen.
  „Alles auf Standard zurücksetzen“ löscht diese Zeilen.
- **Cache:** Beim Speichern entsteht eine neue CSS-Datei, deren Name eine Prüfsumme des Inhalts enthält. Alle Seiten verweisen sofort
  auf die neue Datei; Browser und Server dürfen jede Datei ein Jahr lang speichern. Welche Datei aktuell ist, steht in der Einstellung
  `design_cache`, die ohnehin bei jedem Seitenaufruf geladen wird (keine zusätzliche Datenbankabfrage). Fehlt die Datei oder ändern
  sich mit einem Update die Standardwerte, wird sie beim nächsten Aufruf neu erzeugt. Ist `public/media/design` nicht beschreibbar,
  liefert die Anwendung dasselbe CSS unter `/designsystem.css` aus.
- **Sicherheit:** Kein Eingabefeld nimmt CSS-Text an. Farben nur als HEX, Zahlen werden geprüft und neu formatiert, Schriften, Schatten
  und Texttransformation nur aus festen Listen. Eigene Schriftnamen nur aus Buchstaben, Ziffern, Leerzeichen und Bindestrichen,
  Schriftdateien werden am Inhalt erkannt. Recht „Designsystem“ (nur Administratoren), CSRF-Schutz, jede Änderung im Änderungsprotokoll.
- **Responsive:** Schriftgrößen, Sektionsabstand und Seitenrand haben einen Handy- und einen Computerwert. Dazwischen (Tablet) rechnet
  `clamp()` stufenlos um. Der Umrechnungsbereich (Standard 375 bis 1280 px Bildschirmbreite) ist einstellbar.
- **Abgeleitete Werte** (nicht einzeln einstellbar, folgen automatisch): getönte Flächen wie `--color-primary-soft`
  (`color-mix()` aus Primär- und Kartenfarbe), Abstandsskala `--space-1` … `--space-9` (× Abstandsfaktor), `--radius-shape`.

## Farben

| Variable | Standard | Rolle |
| --- | --- | --- |
| `--color-primary` | `#3f8d7b` | Markengrün: Flächen, Symbole, Rauten, Rahmen. Keine weiße Schrift darauf |
| `--color-primary-strong` | `#317566` | Grün für kleine Schrift und Symbole auf hellem Grund (5,4:1) |
| `--color-secondary` | `#0e3042` | Dunkelblau: Kopfzeile oben, Startbereich, Fußbereich, Infokästen |
| `--color-accent` | `#e8a33d` | sparsam: Sterne, „wenige Plätze“, Hinweisleisten |
| `--color-background` / `--color-surface` / `--color-surface-alt` | `#f6f9f8` / `#ffffff` / `#eaf1ef` | Seite / Karten und Inhaltsbereiche / getönte Abschnitte |
| `--color-text` / `--color-text-muted` / `--color-heading` | `#22394a` / `#4a5b66` / `#0e3042` | Fließtext / Nebentext / Überschriften und Hervorhebungen |
| `--color-link` / `--color-link-hover` | `#317566` / `#285f53` | Links |
| `--color-button` / `-text` / `-hover` / `-active` | `#317566` / `#ffffff` / `#285f53` / `#1f4c42` | Hauptbuttons in allen Zuständen |
| `--color-focus` | `#e8a33d` | Fokusrahmen für Tastaturbedienung |
| `--color-on-dark` / `-muted` / `-accent` | `#ffffff` / `#c5d3d9` / `#8fd3c1` | Schrift auf dunklen Flächen |
| `--color-border` / `-border-strong` / `-input-border` / `-input-background` | `#d3e0dc` / `#b9cdc7` / `#c6d5d1` / `#fbfdfc` | Linien, Kartenrahmen, Formularfelder |
| `--color-success` / `--color-warning` / `--color-error` | `#276b44` / `#9a5b00` / `#b42318` | Meldungen, Formularfehler |
| `--color-shadow` | `#0e3042` | Grundfarbe der Schatten |

Die Verwaltung zeigt einen Hinweis, wenn eine Farbkombination den Kontrast von 4,5:1 unterschreitet (Speichern bleibt möglich).

## Typografie

Standard: **Plus Jakarta Sans** (variabel, SIL OFL) für Überschriften, Fließtext und Bedienelemente; lokal ausgeliefert, `font-display: swap`.
Weitere Schriften ohne Upload: Inter, Open Sans, Source Sans 3, Nunito, Lora, Source Serif 4, Merriweather, Systemschriften.
Die Schriften von Überschrift und Fließtext werden vorab geladen (`preload`).

| Variable | Handy → Computer | Einsatz |
| --- | --- | --- |
| `--font-size-display` | 40 → 60 px | große Überschrift im Startbereich |
| `--font-size-h1` … `--font-size-h6` | 32→42, 26→34, 20→22, 18, 16→17, 15 px | Überschriften |
| `--font-size-lead` | 19 → 21 px | Einleitungen |
| `--font-size-body` | 17 px | Fließtext |
| `--font-size-small` / `--font-size-caption` | 15 / 13 px | Meta, Labels / Badges, kleine Zeilen |
| `--font-size-nav` / `--font-size-button` / `--font-size-input` | 15 / 17 / 17 px | Navigation, Buttons, Formularfelder |
| `--font-size-price` | 24 px | Preise und Kennzahlen |

Gewichte (`--font-weight-body/heading/display/ui/strong`): 400, 700, 800, 600, 700. Zeilenhöhen (`--line-height-body/compact/heading/heading-small/display/ui`):
1.65, 1.5, 1.15, 1.3, 1.06, 1.2. Buchstabenabstand, Texttransformation und Schriftstil haben eigene Variablen (`--letter-spacing-*`,
`--text-transform-*`, `--font-style-*`). Zeilenlänge im Fließtext max. 68 Zeichen (`--text-measure`).

## Abstände und Raster

Skala `--space-1` … `--space-9`: 4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96 px, jeweils × `--space-scale` (Standard 1).
`--section-spacing` 44 px (Handy) → 62 px (Computer), `--content-max-width` 1200 px, `--page-gutter` 16 → 24 px.
`--space-heading` 16, `--space-paragraph` 16, `--space-element` 24 (Raster, Spalten), `--space-section-head` 32, `--card-padding` 24 px.

## Form und Tiefe

Radien: `--radius-small` 8 (Menü, Badges) · `--radius-input` 12 · `--radius-card` 14 · `--radius-image` 24 (Bilder, große Flächen) ·
`--radius-button` 999 (Pille). Signatur-Ecke: `--radius-shape` = drei große Radien und `--radius-corner` (6 px) unten links.
Rahmen: `--border-width` 1, `--border-width-input` 1,5, `--border-width-button` 2, Fokus `--focus-ring-width` 3 px.
Schatten in fünf Stufen (kein, kaum sichtbar, weich, mittel, kräftig): `--shadow-small`, `--shadow-card`, `--shadow-raised`.
Keine Verläufe auf Text, kein Glassmorphism.

## Komponenten

- **Buttons:** primär (`--color-button`, Schrift `--color-button-text`, Hover/aktiv eigene Farben), sekundär (Rahmen `--color-secondary`), ruhig (Text mit Pfeil). Höhe `--button-height` (≥ 44 px empfohlen), Fokusring `--focus-ring-width` in `--color-focus` mit 2 px Abstand, deaktiviert mit 45 % Deckkraft und `aria-disabled`.
- **Links:** `--color-link`, unterstrichen im Fließtext, Hover `--color-link-hover`.
- **Formulare:** Höhe `--input-height`, Rahmen `--border-width-input` in `--color-input-border`, Radius `--radius-input`. Label über dem Feld, Pflichtfelder markiert, Fehlertext unter dem Feld mit Icon, `aria-describedby`.
- **Karten:** Prüfungskarte (Bild 3:2, Niveau-Raute, Preis, nächster Termin, Plätze), Leistungskarte (Bild, Icon, Text, Pfeil-Link), Terminzeile.
- **Status-Pillen:** verfügbar (grün), wenige Plätze (Akzent), ausgebucht/geschlossen (grau).
- **Navigation:** Topbar (Telefon, WhatsApp, Standorte), Header weiß und sticky, Dropdown mit Kurzbeschreibung, mobiles Menü als Vollbild-Panel.
- **Mobile Schnellzugriffsleiste:** Anrufen · WhatsApp · Prüfung buchen, fest unten, Seite erhält Abstand nach unten.
- **Footer:** Sekundärfarbe, Spalten Leistungen, Prüfungen, Unternehmen, Standorte, Stimmen der Teilnehmenden, Rechtliches.

## Bilder und Icons

Fotos im Querformat 3:2 bzw. Hero 2:1, als WebP in 480/960/1440/1920 px, `loading="lazy"` außer Hero.
Bilder mit eingebautem Text (AVGS-Grafiken, A2-B1) nur als Karten, nie als Hero; Inhalt steht zusätzlich als Text.
Icons: Lucide (ISC-Lizenz), 2 px Linie, als SVG-Sprite eingebettet. Keine Emojis als Icons.

## Bewegung

Nur sanftes Einblenden von unten (8 px, 400 ms) und Hover-Anhebung von Karten (2 px).
Bei `prefers-reduced-motion: reduce` sind alle Animationen aus.
