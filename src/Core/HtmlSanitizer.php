<?php
declare(strict_types=1);

namespace Mea\Core;

/**
 * Bereinigt Editor-HTML serverseitig mit einer strikten Positivliste (HTML Purifier).
 * Erlaubt: Überschriften, Absätze, Listen, Links, Tabellen, Bilder aus der Mediathek, Zitate.
 */
final class HtmlSanitizer
{
    private \HTMLPurifier $purifier;

    public function __construct(string $cacheDir)
    {
        $config = \HTMLPurifier_Config::createDefault();
        @mkdir($cacheDir, 0750, true);
        $config->set('Cache.SerializerPath', $cacheDir);
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', implode(',', [
            'h2', 'h3', 'h4', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
            'ul', 'ol', 'li', 'blockquote', 'hr',
            'a[href|title]',
            'table', 'thead', 'tbody', 'tr', 'th[scope|colspan|rowspan]', 'td[colspan|rowspan]', 'caption',
            'img[src|alt|width|height]',
        ]));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
        // Bilder nur aus der eigenen Mediathek
        $config->set('URI.DisableExternalResources', true);
        $config->set('Attr.AllowedFrameTargets', []);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('Core.Encoding', 'UTF-8');
        $this->purifier = new \HTMLPurifier($config);
    }

    public function clean(string $html): string
    {
        $clean = trim($this->purifier->purify($html));
        // Externe Links sicher öffnen; rel wird hier statt im Purifier ergänzt
        return preg_replace('#<a href="(https?://[^"]+)"#', '<a href="$1" rel="noopener"', $clean) ?? $clean;
    }
}
