<?php
declare(strict_types=1);

namespace Mea\Module\Content;

use Mea\Core\Crypto;
use Mea\Core\Database;

/**
 * Holt Gesamtbewertung und Bewertungen aus dem Google-Unternehmensprofil (Google Places API, serverseitig).
 * Besucher laden dabei nichts von Google. Abgerufene Bewertungen werden bei jedem Abruf ersetzt;
 * ob eine Bewertung auf der Website erscheint, entscheidet die Verwaltung (Anzeigen/Ausblenden bleibt erhalten).
 *
 * Einstellungen: „google“ (profile_url, rating, count, as_of – öffentlich) und
 * „google_api“ (key verschlüsselt, place_id, last_sync_at, last_error – nur intern).
 */
final class GoogleReviewSync
{
    private const BASE = 'https://places.googleapis.com/v1/';
    private const CRYPTO_CONTEXT = 'google-places-key';

    /** @var \Closure(string,string,array<string,string>,?array<string,mixed>):array{status:int,body:array<string,mixed>} */
    private \Closure $http;

    public function __construct(private Database $db, private SettingsRepository $settings, private Crypto $crypto, ?\Closure $http = null)
    {
        $this->http = $http ?? self::guzzle(...);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function apiKey(): string
    {
        $enc = (string) (($this->settings->get('google_api', []) ?? [])['key'] ?? '');
        if ($enc === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($enc, self::CRYPTO_CONTEXT);
        } catch (\RuntimeException) {
            return '';
        }
    }

    /** Schlüssel speichern (leer = unverändert lassen, „-“ = löschen). */
    public function saveConnection(string $key, string $placeId): void
    {
        $api = (array) ($this->settings->get('google_api', []) ?? []);
        $key = trim($key);
        if ($key === '-') {
            $api['key'] = '';
        } elseif ($key !== '') {
            $api['key'] = $this->crypto->encrypt($key, self::CRYPTO_CONTEXT);
        }
        $placeId = trim($placeId);
        if ($placeId !== ($api['place_id'] ?? '')) {
            $api['place_id'] = $placeId;
        }
        $this->settings->set('google_api', $api);
    }

    /** @return array{rating:?float,count:int,reviews:int} */
    public function sync(\DateTimeImmutable $now): array
    {
        $api = (array) ($this->settings->get('google_api', []) ?? []);
        try {
            $key = $this->apiKey();
            if ($key === '') {
                throw new \RuntimeException('Kein API-Schlüssel hinterlegt.');
            }
            $placeId = trim((string) ($api['place_id'] ?? ''));
            if ($placeId === '') {
                $placeId = $this->findPlaceId($key);
                $api['place_id'] = $placeId;
            }
            $r = ($this->http)('GET', self::BASE . 'places/' . rawurlencode($placeId) . '?languageCode=de&regionCode=DE', [
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => 'id,displayName,rating,userRatingCount,googleMapsUri,reviews',
            ], null);
            if ($r['status'] !== 200) {
                throw new \RuntimeException('Google meldet: ' . self::errorText($r));
            }
            $place = $r['body'];
            $result = $this->store($place, $now);
            $api['last_sync_at'] = $now->format('Y-m-d H:i:s');
            $api['last_error'] = '';
            $this->settings->set('google_api', $api);
            return $result;
        } catch (\Throwable $e) {
            $api['last_error'] = mb_substr($e->getMessage(), 0, 300);
            $api['last_error_at'] = $now->format('Y-m-d H:i:s');
            $this->settings->set('google_api', $api);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /** Sucht das Profil über Firmenname und Ort; passt die Kunden-Nr. (cid) aus dem Profil-Link, wird genau dieses genommen. */
    private function findPlaceId(string $key): string
    {
        $company = (array) ($this->settings->get('company', []) ?? []);
        $name = trim((string) ($company['legal_name'] ?? $company['name'] ?? 'MEA Bildungszentrum GmbH'));
        $city = (string) ($this->db->value('SELECT city FROM locations WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1') ?? '');
        $r = ($this->http)('POST', self::BASE . 'places:searchText', [
            'X-Goog-Api-Key' => $key,
            'X-Goog-FieldMask' => 'places.id,places.displayName,places.googleMapsUri',
        ], ['textQuery' => trim($name . ' ' . $city), 'languageCode' => 'de', 'regionCode' => 'DE']);
        if ($r['status'] !== 200) {
            throw new \RuntimeException('Google meldet: ' . self::errorText($r));
        }
        $places = (array) ($r['body']['places'] ?? []);
        if ($places === []) {
            throw new \RuntimeException('Das Unternehmensprofil wurde bei Google nicht gefunden. Bitte die Place-ID eintragen.');
        }
        $cid = self::cidFromUrl((string) (($this->settings->get('google', []) ?? [])['profile_url'] ?? ''));
        if ($cid !== null) {
            foreach ($places as $p) {
                if (str_contains((string) ($p['googleMapsUri'] ?? ''), 'cid=' . $cid)) {
                    return (string) $p['id'];
                }
            }
        }
        return (string) $places[0]['id'];
    }

    /** Kunden-Nr. aus einem Google-Maps-Link („?cid=…“ oder „…!1s0x…:0x…“). */
    public static function cidFromUrl(string $url): ?string
    {
        if (preg_match('/[?&]cid=(\d+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/0x[0-9a-f]+:0x([0-9a-f]{1,16})/i', $url, $m)) {
            $n = unpack('J', (string) hex2bin(str_pad($m[1], 16, '0', STR_PAD_LEFT)))[1];
            return sprintf('%u', $n); // als vorzeichenlose 64-Bit-Zahl
        }
        return null;
    }

    /** @param array<string,mixed> $place @return array{rating:?float,count:int,reviews:int} */
    private function store(array $place, \DateTimeImmutable $now): array
    {
        $rating = isset($place['rating']) ? round((float) $place['rating'], 1) : null;
        $count = (int) ($place['userRatingCount'] ?? 0);
        $g = (array) ($this->settings->get('google', []) ?? []);
        if ($rating !== null && $count > 0) {
            $g['rating'] = number_format($rating, 1, ',', '');
            $g['count'] = $count;
            $g['as_of'] = $now->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y');
        }
        if (trim((string) ($g['profile_url'] ?? '')) === '' && !empty($place['googleMapsUri'])) {
            $g['profile_url'] = (string) $place['googleMapsUri'];
        }
        $this->settings->set('google', $g);

        $seen = [];
        foreach ((array) ($place['reviews'] ?? []) as $rv) {
            $ref = (string) ($rv['name'] ?? '');
            $stars = (int) ($rv['rating'] ?? 0);
            if ($ref === '' || $stars < 1 || $stars > 5) {
                continue;
            }
            $seen[] = $ref;
            $text = trim((string) ($rv['originalText']['text'] ?? $rv['text']['text'] ?? ''));
            $published = isset($rv['publishTime']) ? substr((string) $rv['publishTime'], 0, 10) : null;
            $author = mb_substr(trim((string) ($rv['authorAttribution']['displayName'] ?? '')) ?: 'Google-Nutzer', 0, 120);
            $authorUrl = (string) ($rv['authorAttribution']['uri'] ?? '');
            $authorUrl = str_starts_with($authorUrl, 'https://') ? mb_substr($authorUrl, 0, 255) : null;
            $this->db->run(
                "INSERT INTO google_reviews (google_ref, source, author_name, author_url, rating, body, published_at)
                 VALUES (?, 'google', ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE author_name = VALUES(author_name), author_url = VALUES(author_url), rating = VALUES(rating),
                   body = VALUES(body), published_at = VALUES(published_at)",
                [$ref, $author, $authorUrl, $stars, $text === '' ? null : $text, $published],
            );
        }
        // Bewertungen, die Google nicht mehr liefert, werden entfernt (keine dauerhafte Kopie von Google-Inhalten)
        if ($seen !== []) {
            $in = implode(',', array_fill(0, count($seen), '?'));
            $this->db->run("DELETE FROM google_reviews WHERE source = 'google' AND google_ref NOT IN ($in)", $seen);
        }
        return ['rating' => $rating, 'count' => $count, 'reviews' => count($seen)];
    }

    /** @param array{status:int,body:array<string,mixed>} $r */
    private static function errorText(array $r): string
    {
        $msg = (string) ($r['body']['error']['message'] ?? '');
        return $msg !== '' ? mb_substr($msg, 0, 200) : 'Fehler ' . $r['status'];
    }

    /** @param array<string,string> $headers @param array<string,mixed>|null $json @return array{status:int,body:array<string,mixed>} */
    private static function guzzle(string $method, string $url, array $headers, ?array $json): array
    {
        $client = new \GuzzleHttp\Client(['http_errors' => false, 'timeout' => 20, 'connect_timeout' => 10]);
        $opts = ['headers' => $headers + ['Accept' => 'application/json']];
        if ($json !== null) {
            $opts['json'] = $json;
        }
        $res = $client->request($method, $url, $opts);
        $body = json_decode((string) $res->getBody(), true);
        return ['status' => $res->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
