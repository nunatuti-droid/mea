# MEA Designsystem

Leitidee: **„Klarer Weg zum Ziel“.** Die Raute aus dem MEA-Logo ist das wiederkehrende Element:
kleine Rauten als Aufzählungszeichen und Eyebrow-Marker, große Rauten als ruhige Hintergrundformen,
Bildrahmen mit einer „spitzen“ Ecke (drei große Radien, eine kleine Ecke unten links).
Fotos der MEA-Standorte tragen die Seite, Grün steht für Handlung, Dunkelblau für Vertrauen.

Referenzen: die bestehende MEA-Website (Inhalte, Marke), perfekt-deutsch.de (Nutzerführung zur Prüfung),
Eduma (Kartenlogik, Abschnittsfolge). Es wurden keine Gestaltungselemente kopiert.

Alle Werte stehen als CSS-Variablen in `public/assets/css/app.css` (`:root`). Seiten verwenden nur Namen, nie Rohwerte.

## Farben

| Token | Wert | Rolle | Kontrast |
| --- | --- | --- | --- |
| `--c-brand` | `#3f8d7b` | Markengrün (Vorgabe): Flächen, Icons, große Schrift ≥ 24 px, Rahmen | 3,96:1 auf Weiß |
| `--c-action` | `#317566` | Buttons mit weißer Schrift, Links im Fließtext | 5,43:1 auf Weiß |
| `--c-action-hover` | `#285f53` | Hover/aktiv für Buttons und Links | 7,4:1 |
| `--c-brand-soft` | `#e7f2ef` | getönte Flächen, Badges | – |
| `--c-ink` | `#0e3042` | Dunkelblau (Vorgabe): Überschriften, Header-Topbar, Footer, Hero | 13,8:1 |
| `--c-text` | `#22394a` | Fließtext | 12,0:1 |
| `--c-muted` | `#4a5b66` | Nebentext, Metadaten | 7,0:1 |
| `--c-surface` | `#ffffff` | Seitenhintergrund, Karten | – |
| `--c-surface-alt` | `#f4f8f7` | Abschnittswechsel | – |
| `--c-border` | `#dbe6e3` | Linien, Kartenrahmen | – |
| `--c-accent` | `#e8a33d` | sparsam: „wenige Plätze“, Sterne, Hervorhebung auf Dunkel | 6,4:1 zu Ink |
| `--c-brand-light` | `#8fd3c1` | Akzent auf dunklem Grund | 8,1:1 zu Ink |
| `--c-success` / `--c-warning` / `--c-danger` | `#276b44` / `#9a5b00` / `#b42318` | Status, Formularfehler | ≥ 4,5:1 |

Regel: Weiße Schrift steht nie auf `--c-brand`, sondern auf `--c-action` oder `--c-ink`.

## Typografie

Eine Familie: **Plus Jakarta Sans** (variabel, SIL OFL), lokal ausgeliefert, `font-display: swap`.

| Token | Größe | Zeilenhöhe | Einsatz |
| --- | --- | --- | --- |
| `--fs-display` | 40–60 px fließend | 1.08 | Hero-H1 |
| `--fs-h1` | 34–48 px | 1.12 | Seiten-H1 |
| `--fs-h2` | 28–38 px | 1.18 | Abschnittstitel |
| `--fs-h3` | 20–22 px | 1.3 | Kartentitel |
| `--fs-lead` | 19–21 px | 1.6 | Einleitungen |
| `--fs-base` | 17 px | 1.65 | Fließtext |
| `--fs-sm` | 15 px | 1.5 | Meta, Labels |
| `--fs-xs` | 13 px | 1.4 | Badges, Eyebrows (Versalien, +0.08em) |

Gewichte: 400 Text, 600 Labels/Buttons, 700 Überschriften, 800 Display. Zeilenlänge max. 68 Zeichen (`--measure`).

## Abstände und Raster

8-px-Skala: `--s-1` 4 · `--s-2` 8 · `--s-3` 12 · `--s-4` 16 · `--s-5` 24 · `--s-6` 32 · `--s-7` 48 · `--s-8` 64 · `--s-9` 96.
Abschnitte: 96 px Desktop, 56 px Mobil (`--section`). Inhaltsbreite 1200 px, Seitenrand 16 px (Mobil) / 24 px.

## Form und Tiefe

Radien: `--r-sm` 8 (Inputs, Badges) · `--r-md` 14 (Karten) · `--r-lg` 24 (Bilder, große Flächen) · `--r-pill` (Buttons).
Signatur-Ecke: `--r-shape: 24px 24px 24px 6px` für Bilder und Hero-Flächen.
Schatten: `--sh-1` (Ruhe, kaum sichtbar) und `--sh-2` (Hover, Overlays). Keine Verläufe auf Text, kein Glassmorphism.

## Komponenten

- **Buttons:** primär (`--c-action`, weiß), sekundär (Rahmen `--c-ink`), ruhig (Text mit Pfeil). Höhe ≥ 48 px, Fokusring 3 px `--c-accent` mit 2 px Abstand, deaktiviert mit 45 % Deckkraft und `aria-disabled`.
- **Links:** `--c-action`, unterstrichen im Fließtext, Hover `--c-action-hover`.
- **Formulare:** Label über dem Feld, Pflichtfelder markiert, Fehlertext unter dem Feld mit Icon, `aria-describedby`.
- **Karten:** Prüfungskarte (Bild 3:2, Niveau-Raute, Preis, nächster Termin, Plätze), Leistungskarte (Bild, Icon, Text, Pfeil-Link), Terminzeile.
- **Status-Pillen:** verfügbar (grün), wenige Plätze (Akzent), ausgebucht/geschlossen (grau).
- **Navigation:** Topbar (Telefon, WhatsApp, Standorte), Header weiß und sticky, Dropdown mit Kurzbeschreibung, mobiles Menü als Vollbild-Panel.
- **Mobile Schnellzugriffsleiste:** Anrufen · WhatsApp · Prüfung buchen, fest unten, Seite erhält Abstand nach unten.
- **Footer:** Dunkelblau, Spalten Leistungen, Prüfungen, Unternehmen, Standorte, Stimmen der Teilnehmenden, Rechtliches.

## Bilder und Icons

Fotos im Querformat 3:2 bzw. Hero 2:1, als WebP in 480/960/1440/1920 px, `loading="lazy"` außer Hero.
Bilder mit eingebautem Text (AVGS-Grafiken, A2-B1) nur als Karten, nie als Hero; Inhalt steht zusätzlich als Text.
Icons: Lucide (ISC-Lizenz), 2 px Linie, als SVG-Sprite eingebettet. Keine Emojis als Icons.

## Bewegung

Nur sanftes Einblenden von unten (8 px, 400 ms) und Hover-Anhebung von Karten (2 px).
Bei `prefers-reduced-motion: reduce` sind alle Animationen aus.
