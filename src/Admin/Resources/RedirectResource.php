<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\Crud\Resource;
use Mea\Core\Request;

/** Weiterleitungen alter Adressen (Umzug von WordPress) und Protokoll nicht gefundener Seiten. */
final class RedirectResource extends Resource
{
    public string $key = 'weiterleitungen';
    public string $table = 'redirects';
    public string $permission = 'content';
    public string $singular = 'Weiterleitung';
    public string $plural = 'Weiterleitungen';
    public string $entity = 'redirect';
    public string $intro = 'Alte Adressen werden dauerhaft (301) auf neue Seiten umgeleitet oder als entfernt (410) gemeldet. Ein * am Ende gilt für alle Unteradressen, z. B. /alter-bereich/*. Unten stehen Adressen, die Besucher zuletzt vergeblich aufgerufen haben.';
    public string $orderBy = 'source_path';
    public array $searchColumns = ['source_path', 'target_url'];
    public bool $optimisticLock = false;
    public ?string $indexPanel = 'admin/redirects_panel.twig';

    public function titleOf(array $row): string
    {
        return (string) $row['source_path'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'source_path', 'label' => 'Alte Adresse'],
            ['key' => 'target_url', 'label' => 'Ziel'],
            ['key' => 'status_code', 'label' => 'Art'],
            ['key' => 'hits', 'label' => 'Aufrufe'],
        ];
    }

    public function fields(?array $row): array
    {
        return [
            ['name' => 'source_path', 'label' => 'Alte Adresse', 'required' => true, 'max' => 255,
                'help' => 'Pfad ohne Domain, mit / am Anfang, z. B. /alte-seite/. Ein * am Ende gilt für alle Unteradressen.'],
            ['name' => 'status_code', 'label' => 'Art', 'type' => 'select', 'numeric' => true, 'default' => '301',
                'options' => ['301' => '301 – dauerhaft umgeleitet', '302' => '302 – vorübergehend umgeleitet', '410' => '410 – dauerhaft entfernt']],
            ['name' => 'target_url', 'label' => 'Ziel', 'max' => 255,
                'help' => 'Neue Adresse auf dieser Website (z. B. /kontakt/) oder vollständige https-Adresse. Bei 410 leer lassen.'],
        ];
    }

    public function prefill(Request $req): array
    {
        $path = (string) ($req->query['quelle'] ?? '');
        return self::validPath($path) ? ['source_path' => $path, 'status_code' => '301'] : [];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        $src = (string) ($values['source_path'] ?? '');
        $code = (int) ($values['status_code'] ?? 0);
        $target = trim((string) ($values['target_url'] ?? ''));
        if (!self::validPath($src)) {
            $e['source_path'] = 'Bitte einen Pfad mit / am Anfang angeben, ohne Leerzeichen und ohne Domain.';
        } elseif (str_starts_with($src, '/verwaltung/') || in_array($src, ['/', '/*', '/sitemap.xml', '/robots.txt'], true)) {
            $e['source_path'] = 'Diese Adresse kann nicht umgeleitet werden.';
        } elseif ($this->isTaken('source_path', $src, $id)) {
            $e['source_path'] = 'Für diese Adresse gibt es bereits eine Regel.';
        }
        if (!in_array($code, [301, 302, 410], true)) {
            $e['status_code'] = 'Bitte eine Art wählen.';
        }
        if ($code === 410 && $target !== '') {
            $e['target_url'] = 'Bei „entfernt“ (410) bitte kein Ziel angeben.';
        }
        if ($code !== 410) {
            if ($target === '') {
                $e['target_url'] = 'Bitte ein Ziel angeben.';
            } elseif (!self::validPath($target) && !preg_match('#^https://[^\s/]+(/[^\s]*)?$#', $target)) {
                $e['target_url'] = 'Ziel muss mit / beginnen oder eine vollständige https-Adresse sein.';
            } elseif (str_contains($target, '*')) {
                $e['target_url'] = 'Das Ziel darf kein * enthalten.';
            } elseif ($target === $src || (str_ends_with($src, '*') && str_starts_with($target, substr($src, 0, -1)))) {
                $e['target_url'] = 'Das Ziel würde wieder auf diese Regel führen (Endlosschleife).';
            }
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, \Mea\Admin\AdminContext $ctx): array
    {
        if ((int) $values['status_code'] === 410) {
            $values['target_url'] = null;
        }
        $this->db->run('UPDATE not_found_log SET resolved_at = UTC_TIMESTAMP() WHERE path = ?', [$values['source_path']]);
        return $values;
    }

    private static function validPath(string $p): bool
    {
        return (bool) preg_match('#^/[\x21-\x7E]{0,254}$#', $p) && !str_contains($p, '..') && !str_contains($p, '?') && !str_contains($p, '#')
            && (substr_count($p, '*') === 0 || (substr_count($p, '*') === 1 && str_ends_with($p, '*')));
    }

    /** Häufigste offene 404-Adressen für den Hinweisbereich. @return list<array<string,mixed>> */
    public function notFound(int $limit = 30): array
    {
        return $this->db->all('SELECT path, hits, referrer_host, last_seen_at FROM not_found_log WHERE resolved_at IS NULL ORDER BY hits DESC, last_seen_at DESC LIMIT ' . max(1, $limit));
    }
}
