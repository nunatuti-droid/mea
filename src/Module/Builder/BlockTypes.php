<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

/**
 * Alle Bausteine des Seitenbaukastens: Bezeichnung, Felder mit Standardwerten, erlaubte Verschachtelung.
 * Jeder Baustein hat ein Template unter templates/blocks/<typ>.twig, das Website, Vorschau und Editor gemeinsam nutzen.
 *
 * Feldtypen: text (eine Zeile), textarea (mehrzeilig ohne HTML), rich (bereinigtes HTML), url, media (Bild aus der Mediathek),
 * media_list, select, bool, number, icon, lines (eine Angabe pro Zeile), items (Liste mit Unterfeldern).
 */
final class BlockTypes
{
    public const CATEGORIES = [
        'layout' => 'Struktur',
        'basic' => 'Grundelemente',
        'media' => 'Bilder und Medien',
        'content' => 'Inhaltsbausteine',
        'site' => 'MEA-Bausteine (mit Daten der Verwaltung)',
    ];

    /** Bausteine, die nur direkt in einem bestimmten Elternelement stehen dürfen */
    private const ONLY_IN = ['column' => ['columns'], 'button' => ['buttons', 'group', 'column', 'section']];

    /** Strukturelemente, die Kinder aufnehmen; null = alle außer den genannten */
    private const CHILDREN = [
        'section' => ['except' => ['section', 'column']],
        'columns' => ['only' => ['column']],
        'column' => ['except' => ['section', 'column']],
        'group' => ['except' => ['section', 'column']],
        'buttons' => ['only' => ['button']],
    ];

    public const ICONS = ['phone', 'message-circle', 'calendar', 'calendar-days', 'map-pin', 'clock', 'check', 'arrow-right', 'chevron-down', 'mail', 'users',
        'briefcase', 'languages', 'award', 'graduation-cap', 'user-check', 'handshake', 'house', 'file-text', 'shield-check', 'star', 'info', 'euro',
        'building-2', 'route', 'external-link', 'quote', 'plane', 'book-open', 'target', 'heart-handshake', 'circle-check', 'ticket', 'search',
        'clipboard-check', 'map', 'arrow-up-right', 'badge-check', 'sparkles', 'chevron-right', 'circle-alert', 'download', 'send', 'play'];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $types = null;

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }
        $levels = ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6'];
        $text = fn(string $name, string $label, string $default = '', int $max = 200, array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'text', 'default' => $default, 'max' => $max] + $o;
        $area = fn(string $name, string $label, string $default = '', int $max = 1000, array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'textarea', 'default' => $default, 'max' => $max] + $o;
        $rich = fn(string $name, string $label, string $default = '', array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'rich', 'default' => $default] + $o;
        $url = fn(string $name, string $label, string $default = '') => ['name' => $name, 'label' => $label, 'type' => 'url', 'default' => $default];
        $sel = fn(string $name, string $label, array $options, string $default, array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'select', 'options' => $options, 'default' => $default] + $o;
        $bool = fn(string $name, string $label, bool $default = false, array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'bool', 'default' => $default] + $o;
        $num = fn(string $name, string $label, int $default, int $min, int $max, array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'number', 'default' => $default, 'min' => $min, 'max' => $max] + $o;
        $media = fn(string $name = 'media', string $label = 'Bild') => ['name' => $name, 'label' => $label, 'type' => 'media', 'default' => null];
        $icon = fn(string $name = 'icon', string $label = 'Symbol', string $default = '') => ['name' => $name, 'label' => $label, 'type' => 'icon', 'default' => $default];
        $items = fn(string $name, string $label, array $fields, array $default, int $max = 24, string $title = 'title') => ['name' => $name, 'label' => $label, 'type' => 'items', 'fields' => $fields, 'default' => $default, 'max_items' => $max, 'item_title' => $title];
        $lines = fn(string $name, string $label, string $default = '', array $o = []) => ['name' => $name, 'label' => $label, 'type' => 'lines', 'default' => $default] + $o;
        $level = fn(string $default = 'h2') => $sel('level', 'Überschriftenebene', $levels, $default, ['help' => 'Für die Gliederung der Seite (Suchmaschinen, Screenreader). Pro Seite genau eine H1.']);
        $btnStyles = ['primary' => 'Hauptbutton', 'secondary' => 'Umrandet', 'light' => 'Hell (auf dunklem Grund)', 'ghost-light' => 'Umrandet hell (auf dunklem Grund)', 'arrow' => 'Textlink mit Pfeil'];

        $t = [];
        // ---------- Struktur ----------
        $t['section'] = ['label' => 'Abschnitt', 'icon' => 'panel-top', 'cat' => 'layout', 'desc' => 'Breiter Bereich mit Hintergrund und Innenabstand',
            'fields' => [
                $sel('bg', 'Hintergrund', ['none' => 'Seitenhintergrund', 'alt' => 'Getönt', 'surface' => 'Weiß', 'ink' => 'Dunkel (Sekundärfarbe)'], 'none'),
                $sel('width', 'Inhaltsbreite', ['normal' => 'Normal (Designsystem)', 'narrow' => 'Schmal (Lesebreite)', 'wide' => 'Breit', 'full' => 'Ganze Breite'], 'normal'),
                $sel('spacing', 'Abstand oben und unten', ['normal' => 'Normal (Designsystem)', 'compact' => 'Klein', 'large' => 'Groß', 'none' => 'Keiner'], 'normal'),
                $text('anchor', 'Sprungmarke (#…)', '', 40, ['pattern' => 'anchor', 'help' => 'Nur Kleinbuchstaben, Ziffern und Bindestriche. Ergibt z. B. /seite/#stimmen.']),
                $text('aria', 'Name für Screenreader', '', 80, ['help' => 'Optional, wenn der Abschnitt keine Überschrift hat.']),
            ]];
        $t['columns'] = ['label' => 'Spalten', 'icon' => 'columns-2', 'cat' => 'layout', 'desc' => 'Zwei bis vier Spalten nebeneinander',
            'fields' => [
                $sel('layout', 'Aufteilung', ['1-1' => '½ ½', '2-1' => '⅔ ⅓', '1-2' => '⅓ ⅔', '3-2' => '⅗ ⅖', '2-3' => '⅖ ⅗', '4-3' => '4/7 3/7', '1-1-1' => '⅓ ⅓ ⅓', '1-1-1-1' => '¼ ¼ ¼ ¼'], '1-1'),
                $sel('gap', 'Abstand zwischen den Spalten', ['normal' => 'Normal', 'small' => 'Klein', 'large' => 'Groß', 'none' => 'Keiner'], 'large'),
                $sel('valign', 'Vertikale Ausrichtung', ['start' => 'Oben', 'center' => 'Mitte', 'end' => 'Unten', 'stretch' => 'Gleiche Höhe'], 'center'),
                $sel('stack', 'Untereinander ab', ['tablet' => 'Tablet', 'mobile' => 'Handy', 'never' => 'Nie'], 'tablet'),
                $bool('reverse', 'Auf kleinen Bildschirmen umgekehrte Reihenfolge'),
            ]];
        $t['column'] = ['label' => 'Spalte', 'icon' => 'square', 'cat' => 'layout', 'hidden' => true, 'fields' => []];
        $t['group'] = ['label' => 'Gruppe', 'icon' => 'box', 'cat' => 'layout', 'desc' => 'Flexibler Bereich: Elemente neben- oder untereinander',
            'fields' => [
                $sel('direction', 'Anordnung', ['column' => 'Untereinander', 'row' => 'Nebeneinander'], 'column'),
                $sel('gap', 'Abstand', ['normal' => 'Normal', 'small' => 'Klein', 'large' => 'Groß', 'none' => 'Keiner'], 'normal'),
                $sel('align', 'Ausrichtung quer', ['stretch' => 'Strecken', 'start' => 'Anfang', 'center' => 'Mitte', 'end' => 'Ende'], 'stretch'),
                $sel('justify', 'Verteilung', ['start' => 'Anfang', 'center' => 'Mitte', 'end' => 'Ende', 'between' => 'Gleichmäßig verteilt'], 'start'),
                $bool('wrap', 'Umbrechen, wenn der Platz nicht reicht', true),
                $bool('card', 'Als Karte darstellen (Hintergrund, Rahmen, Schatten)'),
            ]];
        $t['spacer'] = ['label' => 'Abstand', 'icon' => 'move-vertical', 'cat' => 'layout', 'desc' => 'Leerer Raum (Höhe je Gerät unter Stil einstellbar)',
            'fields' => [$num('height', 'Höhe in px', 48, 0, 400)]];
        $t['divider'] = ['label' => 'Trennlinie', 'icon' => 'minus', 'cat' => 'layout',
            'fields' => [$sel('look', 'Darstellung', ['line' => 'Linie', 'diamond' => 'Linie mit Raute'], 'line')]];

        // ---------- Grundelemente ----------
        $t['heading'] = ['label' => 'Überschrift', 'icon' => 'heading-2', 'cat' => 'basic',
            'fields' => [
                $text('text', 'Text', 'Neue Überschrift', 300, ['inline' => 'text', 'required' => true]),
                $level(),
                $sel('look', 'Größe', ['auto' => 'Passend zur Ebene', 'display' => 'Sehr groß (Startbereich)', 'h1' => 'wie H1', 'h2' => 'wie H2', 'h3' => 'wie H3', 'h4' => 'wie H4', 'h5' => 'wie H5', 'h6' => 'wie H6', 'sr' => 'Unsichtbar (nur für Screenreader)'], 'auto'),
                $text('anchor', 'Sprungmarke (#…)', '', 40, ['pattern' => 'anchor']),
            ]];
        $t['eyebrow'] = ['label' => 'Kleine Zeile über Überschrift', 'icon' => 'type', 'cat' => 'basic',
            'fields' => [$text('text', 'Text', 'Kurz und knapp', 100, ['inline' => 'text', 'required' => true])]];
        $t['text'] = ['label' => 'Text', 'icon' => 'pilcrow', 'cat' => 'basic', 'desc' => 'Absätze, Listen, Links, fett und kursiv',
            'fields' => [
                $rich('html', 'Text', '<p>Ihr Text.</p>', ['inline' => 'rich']),
                $sel('look', 'Darstellung', ['normal' => 'Fließtext', 'lead' => 'Einleitung (größer)', 'small' => 'Klein', 'muted' => 'Gedämpft'], 'normal'),
            ]];
        $t['buttons'] = ['label' => 'Button-Gruppe', 'icon' => 'rectangle-horizontal', 'cat' => 'basic', 'desc' => 'Mehrere Buttons in einer Reihe',
            'fields' => [$sel('align', 'Ausrichtung', ['start' => 'Links', 'center' => 'Mitte', 'end' => 'Rechts'], 'start')]];
        $t['button'] = ['label' => 'Button', 'icon' => 'rectangle-horizontal', 'cat' => 'basic',
            'fields' => [
                $text('label', 'Beschriftung', 'Mehr erfahren', 80, ['inline' => 'text', 'required' => true]),
                $url('url', 'Ziel', '/kontakt/'),
                $sel('look', 'Art', $btnStyles, 'primary'),
                $sel('size', 'Größe', ['normal' => 'Normal', 'sm' => 'Klein', 'lg' => 'Groß'], 'normal'),
                $icon('icon', 'Symbol', ''),
                $sel('icon_pos', 'Symbol', ['before' => 'vor dem Text', 'after' => 'nach dem Text'], 'before'),
                $bool('blank', 'In neuem Fenster öffnen'),
                $bool('block', 'Ganze Breite'),
            ]];
        $t['icon'] = ['label' => 'Symbol', 'icon' => 'sparkles', 'cat' => 'basic',
            'fields' => [$icon('icon', 'Symbol', 'star'), $sel('look', 'Darstellung', ['badge' => 'Mit Fläche', 'plain' => 'Nur Symbol'], 'badge'),
                $sel('size', 'Größe', ['sm' => 'Klein', 'md' => 'Mittel', 'lg' => 'Groß'], 'md'), $text('label', 'Bedeutung für Screenreader', '', 80), $url('url', 'Link (optional)')]];
        $t['list'] = ['label' => 'Aufzählung', 'icon' => 'list', 'cat' => 'basic',
            'fields' => [$lines('items', 'Punkte (einer pro Zeile)', "Erster Punkt\nZweiter Punkt\nDritter Punkt", ['max' => 60, 'inline_lines' => true]),
                $sel('look', 'Zeichen', ['diamond' => 'Raute', 'check' => 'Haken', 'bullet' => 'Punkt', 'number' => 'Nummeriert'], 'diamond')]];
        $t['link_list'] = ['label' => 'Linkliste', 'icon' => 'link', 'cat' => 'basic',
            'fields' => [$items('items', 'Links', [$text('label', 'Text', '', 120, ['required' => true]), $url('url', 'Ziel'), $text('desc', 'Kurzbeschreibung', '', 160)],
                [['label' => 'Kontakt', 'url' => '/kontakt/', 'desc' => '']], 40, 'label'),
                $sel('look', 'Darstellung', ['arrows' => 'Mit Pfeilen', 'chips' => 'Als Schaltflächen', 'plain' => 'Einfache Liste'], 'arrows')]];
        $t['table'] = ['label' => 'Tabelle', 'icon' => 'table', 'cat' => 'basic',
            'fields' => [$text('caption', 'Beschriftung (für Screenreader)', '', 160),
                $lines('rows', 'Zeilen (Zellen mit | trennen)', "Spalte A | Spalte B\nWert 1 | Wert 2", ['max' => 100]),
                $bool('head', 'Erste Zeile ist Kopfzeile', true), $bool('stack', 'Auf dem Handy als Liste zeigen', true)]];

        // ---------- Bilder und Medien ----------
        $t['image'] = ['label' => 'Bild', 'icon' => 'image', 'cat' => 'media',
            'fields' => [$media(), $text('alt', 'Alternativtext', '', 200, ['help' => 'Leer = Alternativtext aus der Mediathek. Für reine Schmuckbilder „-“ eintragen.']),
                $text('caption', 'Bildunterschrift', '', 200),
                $sel('ratio', 'Seitenverhältnis', ['auto' => 'Original', '16-9' => '16:9', '3-2' => '3:2', '4-3' => '4:3', '5-4' => '5:4', '1-1' => 'Quadrat'], 'auto'),
                $sel('shape', 'Ecken', ['signature' => 'MEA-Form (eine spitze Ecke)', 'round' => 'Abgerundet', 'none' => 'Eckig'], 'signature'),
                $url('url', 'Link (optional)'), $bool('eager', 'Sofort laden (Bild ganz oben auf der Seite)')]];
        $t['image_text'] = ['label' => 'Bild mit Text', 'icon' => 'columns-2', 'cat' => 'media',
            'fields' => [$media(), $text('alt', 'Alternativtext', '', 200), $text('eyebrow', 'Kleine Zeile', '', 100),
                $text('title', 'Überschrift', 'Überschrift', 200, ['inline' => 'text']), $level(), $rich('html', 'Text', '<p>Text neben dem Bild.</p>', ['inline' => 'rich']),
                $text('btn_label', 'Button', '', 80), $url('btn_url', 'Button-Ziel'),
                $sel('side', 'Bild', ['left' => 'links', 'right' => 'rechts'], 'left'),
                $sel('valign', 'Ausrichtung', ['center' => 'Mitte', 'start' => 'Oben'], 'center'),
                $sel('ratio', 'Seitenverhältnis', ['5-4' => '5:4', '3-2' => '3:2', '1-1' => 'Quadrat', '4-3' => '4:3', 'auto' => 'Original'], '5-4')]];
        $t['gallery'] = ['label' => 'Bildergalerie', 'icon' => 'images', 'cat' => 'media',
            'fields' => [['name' => 'images', 'label' => 'Bilder', 'type' => 'media_list', 'default' => [], 'max_items' => 40],
                $sel('cols', 'Spalten', ['2' => '2', '3' => '3', '4' => '4'], '3'),
                $sel('ratio', 'Seitenverhältnis', ['3-2' => '3:2', '1-1' => 'Quadrat', '4-3' => '4:3', 'auto' => 'Original'], '3-2'),
                $bool('captions', 'Alternativtext als Bildunterschrift zeigen')]];
        $t['video'] = ['label' => 'Video', 'icon' => 'play', 'cat' => 'media', 'desc' => 'YouTube oder Vimeo, lädt erst nach Klick',
            'fields' => [$url('url', 'Adresse des Videos (YouTube oder Vimeo)'), $text('title', 'Titel', 'Video', 160, ['required' => true]), $media('poster', 'Vorschaubild'),
                $sel('ratio', 'Seitenverhältnis', ['16-9' => '16:9', '4-3' => '4:3', '1-1' => 'Quadrat'], '16-9')]];

        // ---------- Inhaltsbausteine ----------
        $t['section_head'] = ['label' => 'Abschnittskopf', 'icon' => 'heading-3', 'cat' => 'content', 'desc' => 'Kleine Zeile, Überschrift, Text und Link',
            'fields' => [$text('eyebrow', 'Kleine Zeile', '', 100, ['inline' => 'text']), $text('title', 'Überschrift', 'Überschrift', 200, ['inline' => 'text', 'required' => true]), $level(),
                $area('text', 'Text', '', 600), $text('link_label', 'Link', '', 80), $url('link_url', 'Link-Ziel'),
                $sel('layout', 'Anordnung', ['stack' => 'Untereinander', 'split' => 'Link rechts daneben', 'center' => 'Zentriert'], 'stack')]];
        $t['cards'] = ['label' => 'Karten und Kacheln', 'icon' => 'layout-dashboard', 'cat' => 'content',
            'fields' => [$items('items', 'Karten', [$icon(), $media(), $text('title', 'Titel', '', 120, ['required' => true]), $area('text', 'Text', '', 600),
                $text('link_label', 'Link-Text', '', 60), $url('url', 'Link-Ziel')],
                [['icon' => 'target', 'media' => null, 'title' => 'Karte', 'text' => 'Kurzer Text.', 'link_label' => '', 'url' => '']], 24),
                $sel('cols', 'Spalten', ['2' => '2', '3' => '3', '4' => '4'], '3'),
                $sel('look', 'Darstellung', ['card' => 'Karte mit Rahmen', 'feature' => 'Ohne Rahmen', 'media' => 'Bildkarte'], 'card'),
                $sel('level', 'Überschriftenebene der Karten', ['h3' => 'H3', 'h4' => 'H4', 'h2' => 'H2'], 'h3')]];
        $t['accordion'] = ['label' => 'Akkordeon', 'icon' => 'list-checks', 'cat' => 'content', 'desc' => 'Aufklappbare Fragen oder Abschnitte',
            'fields' => [$items('items', 'Einträge', [$text('title', 'Titel', '', 300, ['required' => true]), $rich('html', 'Inhalt')],
                [['title' => 'Frage', 'html' => '<p>Antwort.</p>']], 60), $bool('open', 'Ersten Eintrag geöffnet zeigen'), $bool('schema', 'Für Suchmaschinen als FAQ kennzeichnen')]];
        $t['tabs'] = ['label' => 'Tabs', 'icon' => 'panels', 'cat' => 'content',
            'fields' => [$items('items', 'Tabs', [$text('title', 'Titel', '', 80, ['required' => true]), $rich('html', 'Inhalt')],
                [['title' => 'Erster Tab', 'html' => '<p>Inhalt.</p>'], ['title' => 'Zweiter Tab', 'html' => '<p>Inhalt.</p>']], 8)]];
        $t['stats'] = ['label' => 'Kennzahlen', 'icon' => 'chart-column', 'cat' => 'content',
            'fields' => [$items('items', 'Zahlen', [$text('value', 'Zahl', '', 20, ['required' => true]), $text('label', 'Beschriftung', '', 80), $text('text', 'Zusatz', '', 160)],
                [['value' => '500+', 'label' => 'Teilnehmende', 'text' => '']], 8, 'value'),
                $sel('look', 'Darstellung', ['plain' => 'Schlicht', 'cards' => 'Karten'], 'plain')]];
        $t['testimonials'] = ['label' => 'Kundenstimmen', 'icon' => 'quote', 'cat' => 'content',
            'fields' => [$sel('source', 'Quelle', ['db' => 'Bewertungen aus der Verwaltung', 'manual' => 'Hier eingeben'], 'db'), $num('limit', 'Höchstens (bei Verwaltung)', 6, 1, 24),
                $items('items', 'Stimmen (bei „Hier eingeben“)', [$area('quote', 'Zitat', '', 800, ['required' => true]), $text('name', 'Name', '', 80), $text('role', 'Rolle', '', 120),
                    $sel('rating', 'Sterne', ['' => 'keine', '5' => '5', '4' => '4', '3' => '3'], '')], [], 12, 'name'),
                $bool('hide_empty', 'Abschnitt ausblenden, wenn es keine Stimmen gibt', true)]];
        $t['cta'] = ['label' => 'Handlungsaufruf', 'icon' => 'megaphone', 'cat' => 'content', 'desc' => 'Farbiges Band mit Kontakt-Buttons',
            'fields' => [$text('title', 'Überschrift', 'Fragen? Wir sind für Sie da.', 160, ['inline' => 'text', 'required' => true]), $area('text', 'Text', 'Schreiben Sie uns oder rufen Sie an.', 400),
                $text('btn_label', 'Button', 'Nachricht schreiben', 60), $url('btn_url', 'Button-Ziel', '/kontakt/'), $bool('phone', 'Telefonnummer als zweiten Button zeigen', true)]];
        $t['download'] = ['label' => 'Downloads', 'icon' => 'download', 'cat' => 'content',
            'fields' => [$items('items', 'Dateien', [$text('label', 'Bezeichnung', '', 120, ['required' => true]), $url('url', 'Datei (Mediathek-Dokument oder Adresse)'), $text('desc', 'Hinweis (z. B. PDF, 200 KB)', '', 80)],
                [], 30, 'label')]];
        $t['contact_info'] = ['label' => 'Kontaktbereich', 'icon' => 'phone', 'cat' => 'content', 'desc' => 'Telefon, WhatsApp, E-Mail und Standorte aus den Firmendaten',
            'fields' => [$text('title', 'Überschrift', 'Kontakt', 80), $bool('phone', 'Telefon', true), $bool('whatsapp', 'WhatsApp', true), $bool('email', 'E-Mail', false),
                $bool('locations', 'Standorte darunter zeigen', true), $sel('look', 'Darstellung', ['brand' => 'Dunkle Box', 'plain' => 'Schlicht'], 'brand')]];

        // ---------- MEA-Bausteine ----------
        $t['hero_home'] = ['label' => 'Startbereich mit Hintergrundbild', 'icon' => 'house', 'cat' => 'site',
            'fields' => [$media('media', 'Hintergrundbild'), $text('badge', 'Kleine Zeile', '', 100, ['inline' => 'text']),
                $text('title', 'Überschrift (H1)', 'Überschrift', 160, ['inline' => 'text', 'required' => true, 'help' => 'Text nach einem Doppelpunkt erscheint in der zweiten Zeile hervorgehoben.']),
                $area('lead', 'Einleitung', '', 400), $text('btn1_label', 'Button 1', 'Unsere Leistungen', 60), $url('btn1_url', 'Button 1 Ziel', '/leistungen/'),
                $text('btn2_label', 'Button 2', '', 60), $url('btn2_url', 'Button 2 Ziel'), $bool('rating', 'Google-Bewertung zeigen', true),
                $items('benefits', 'Vorteile', [$icon(), $text('title', 'Titel', '', 80, ['required' => true])], [], 4)]];
        $t['page_hero'] = ['label' => 'Seitenkopf', 'icon' => 'panel-top', 'cat' => 'site', 'desc' => 'Überschrift (H1), Einleitung, Brotkrumen und Bild',
            'fields' => [$text('eyebrow', 'Kleine Zeile', '', 100, ['inline' => 'text']), $text('title', 'Überschrift (H1)', '', 200, ['inline' => 'text', 'help' => 'Leer = Titel der Seite.']),
                $area('intro', 'Einleitung', '', 600), $media(), $bool('crumbs', 'Brotkrumen-Navigation zeigen', true),
                $text('crumb', 'Name in der Brotkrumen-Navigation', '', 80, ['help' => 'Leer = Überschrift.']),
                $text('parent_label', 'Übergeordnete Seite (Brotkrumen)', '', 80), $url('parent_url', 'Übergeordnete Seite Adresse'),
                $text('btn1_label', 'Button 1', '', 60), $url('btn1_url', 'Button 1 Ziel'), $text('btn2_label', 'Button 2', '', 60), $url('btn2_url', 'Button 2 Ziel')]];
        $t['partners'] = ['label' => 'Partnerlogos', 'icon' => 'badge-check', 'cat' => 'site',
            'fields' => [$text('label', 'Überschrift', 'Zulassungen und Partner', 80), $items('items', 'Logos', [$media(), $text('label', 'Name (nur für Screenreader und Suchmaschinen)', '', 120, ['required' => true])], [], 18, 'label')]];
        $t['services_grid'] = ['label' => 'Leistungen (Karten)', 'icon' => 'briefcase', 'cat' => 'site', 'desc' => 'Alle veröffentlichten Leistungen aus der Verwaltung',
            'fields' => [$num('limit', 'Höchstens (0 = alle)', 0, 0, 24), $text('more', 'Link-Text auf den Karten', 'Mehr erfahren', 40)]];
        $t['exam_cards'] = ['label' => 'Prüfungen (Karten)', 'icon' => 'award', 'cat' => 'site', 'desc' => 'Prüfungskarten mit Preis und nächstem Termin',
            'fields' => [$num('limit', 'Höchstens', 6, 1, 24), $rich('empty', 'Text, wenn keine Prüfung eingetragen ist', '<p>Unsere Prüfungen werden gerade eingetragen. Fragen Sie gerne direkt nach Terminen: <a href="/kontakt/?thema=pruefung">Kontakt aufnehmen</a>.</p>')]];
        $t['exam_list'] = ['label' => 'Prüfungsliste mit Filter', 'icon' => 'list-checks', 'cat' => 'site', 'desc' => 'Alle Prüfungen nach Kategorie, mit Filter',
            'fields' => [$bool('venue', 'Hinweis zum Prüfungsort zeigen', true), $text('other', 'Überschrift für Prüfungen ohne Kategorie', 'Weitere Prüfungen', 80),
                $rich('empty', 'Text, wenn keine Prüfung eingetragen ist', '<p>Unsere Prüfungen werden gerade eingetragen. <a href="/kontakt/?thema=pruefung">Fragen Sie uns nach Terminen.</a></p>'),
                $rich('empty_filtered', 'Text, wenn der Filter nichts findet', '<p>Für diese Auswahl gibt es derzeit keine Prüfung. <a href="/telc-pruefungszentrum/">Alle Prüfungen anzeigen</a></p>')]];
        $t['next_sessions'] = ['label' => 'Nächste Prüfungstermine', 'icon' => 'calendar-range', 'cat' => 'site',
            'fields' => [$num('limit', 'Höchstens', 6, 1, 20), $text('btn', 'Button-Text', 'Termin wählen', 40), $bool('hide_empty', 'Abschnitt ausblenden, wenn es keine Termine gibt', true)]];
        $t['steps'] = ['label' => 'Schritte', 'icon' => 'list-ordered', 'cat' => 'content', 'desc' => 'Nummerierte Schritte nebeneinander',
            'fields' => [$items('items', 'Schritte', [$text('title', 'Titel', '', 80, ['required' => true]), $area('text', 'Text', '', 300)],
                [['title' => 'Erster Schritt', 'text' => ''], ['title' => 'Zweiter Schritt', 'text' => '']], 8)]];
        $t['google_rating'] = ['label' => 'Google-Bewertung', 'icon' => 'star', 'cat' => 'site', 'desc' => 'Sterne und Anzahl aus dem Google-Unternehmensprofil',
            'fields' => [$sel('variant', 'Darstellung', ['inline' => 'Zeile', 'card' => 'Karte'], 'inline')]];
        $t['faq'] = ['label' => 'Häufige Fragen', 'icon' => 'help-circle', 'cat' => 'site', 'desc' => 'Fragen und Antworten aus der Verwaltung (FAQ)',
            'fields' => [$sel('source', 'Welche Fragen', ['home' => 'Für die Startseite markierte', 'faq_page' => 'Für die FAQ-Seite markierte', 'all' => 'Alle aktiven'], 'faq_page'),
                $bool('grouped', 'Nach Kategorie gruppieren'), $num('limit', 'Höchstens (0 = alle)', 0, 0, 100), $bool('schema', 'Für Suchmaschinen als FAQ kennzeichnen', true),
                $text('empty', 'Text, wenn keine Fragen eingetragen sind', 'Noch keine Fragen eingetragen.', 160),
                $bool('hide_empty', 'Abschnitt ausblenden, wenn es keine Fragen gibt')]];
        $t['locations'] = ['label' => 'Standorte', 'icon' => 'map-pin', 'cat' => 'site', 'desc' => 'Alle aktiven Standorte aus der Verwaltung',
            'fields' => [$sel('look', 'Darstellung', ['full' => 'Ausführlich (Adresse, Zeiten, Kontakt)', 'compact' => 'Kompakt'], 'full'), $sel('cols', 'Spalten', ['1' => '1', '2' => '2', '3' => '3'], '2')]];
        $t['price_table'] = ['label' => 'Preistabelle Prüfungen', 'icon' => 'euro', 'cat' => 'site',
            'fields' => [$area('note', 'Hinweis unter der Tabelle', 'Zusatzgebühren und abweichende Terminpreise sehen Sie auf der jeweiligen Prüfungsseite und vor der Zahlung.', 400),
                $rich('empty', 'Text, wenn keine Preise eingetragen sind', '<p>Die Preise werden gerade eingetragen. <a href="/kontakt/?thema=pruefung">Fragen Sie uns gerne direkt.</a></p>')]];
        $t['contact_form'] = ['label' => 'Kontaktformular', 'icon' => 'mail', 'cat' => 'site', 'single' => true, 'desc' => 'Formular für Anfragen (nur einmal pro Seite)',
            'fields' => [$text('title', 'Überschrift', 'Schreiben Sie uns', 120, ['inline' => 'text']), $area('success', 'Bestätigung nach dem Senden', 'Vielen Dank! Ihre Nachricht ist bei uns angekommen. Wir melden uns schnellstmöglich.', 300),
                $text('submit', 'Button', 'Nachricht senden', 40), $text('l_name', 'Feld Name', 'Name', 40), $text('l_email', 'Feld E-Mail', 'E-Mail', 40),
                $text('l_phone', 'Feld Telefon', 'Telefon', 40), $text('l_topic', 'Feld Thema', 'Thema', 40), $text('l_location', 'Feld Standort', 'Bevorzugter Standort', 60),
                $text('l_message', 'Feld Nachricht', 'Ihre Nachricht', 40),
                $rich('privacy', 'Einwilligung', '<p>Ich habe die <a href="/datenschutz/">Datenschutzhinweise</a> gelesen und bin einverstanden, dass meine Angaben zur Bearbeitung meiner Anfrage gespeichert werden.</p>')]];
        $t['exam_venue'] = ['label' => 'Hinweis Prüfungsort', 'icon' => 'info', 'cat' => 'site', 'desc' => 'Wo die Prüfungen stattfinden (aus den Standorten)', 'fields' => []];
        $t['component'] = ['label' => 'Globaler Baustein', 'icon' => 'globe', 'cat' => 'site', 'hidden' => true,
            'fields' => [['name' => 'ref', 'label' => 'Baustein', 'type' => 'component', 'default' => null]]];

        return self::$types = $t;
    }

    /** @return array<string,mixed>|null */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function acceptsChildren(string $type): bool
    {
        return isset(self::CHILDREN[$type]);
    }

    /** Darf $child direkt in $parent stehen? $parent = null für die oberste Ebene der Seite. */
    public static function allowedIn(string $child, ?string $parent): bool
    {
        if ($parent === null) {
            return !in_array($child, ['column', 'button'], true);
        }
        $rule = self::CHILDREN[$parent] ?? null;
        if ($rule === null) {
            return false;
        }
        if (isset($rule['only']) && !in_array($child, $rule['only'], true)) {
            return false;
        }
        if (isset($rule['except']) && in_array($child, $rule['except'], true)) {
            return false;
        }
        if (isset(self::ONLY_IN[$child]) && !in_array($parent, self::ONLY_IN[$child], true)) {
            return false;
        }
        return true;
    }

    /** Standardwerte aller Felder eines Typs. @return array<string,mixed> */
    public static function defaults(string $type): array
    {
        $out = [];
        foreach (self::get($type)['fields'] ?? [] as $f) {
            $out[$f['name']] = $f['default'];
        }
        return $out;
    }

    /** Beschreibung für den Editor (ohne PHP-Interna). @return array<string,mixed> */
    public static function forEditor(): array
    {
        $types = [];
        foreach (self::all() as $key => $t) {
            $types[$key] = $t + ['children' => self::acceptsChildren($key), 'rule' => self::CHILDREN[$key] ?? null, 'only_in' => self::ONLY_IN[$key] ?? null];
        }
        return ['types' => $types, 'categories' => self::CATEGORIES, 'icons' => self::ICONS];
    }
}
