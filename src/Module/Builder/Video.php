<?php
declare(strict_types=1);

namespace Mea\Module\Builder;

/** Erkennt YouTube- und Vimeo-Adressen und baut die datensparsame Einbettungsadresse (lädt erst nach Klick). */
final class Video
{
    /** @return array{src:string, provider:string}|null */
    public static function embed(string $url): ?array
    {
        $url = trim($url);
        if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return ['src' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0', 'provider' => 'YouTube'];
        }
        if (preg_match('~^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{5,12})~', $url, $m)) {
            return ['src' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1&dnt=1', 'provider' => 'Vimeo'];
        }
        return null;
    }
}
