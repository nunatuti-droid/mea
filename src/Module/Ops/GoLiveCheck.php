<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Admin\SystemCheck;
use Mea\Core\App;
use Mea\Core\Request;

/**
 * Prüfungen vor der Umschaltung auf die neue Website (bin/console golive:check).
 * Ergänzt die Systemprüfung um Punkte, die erst beim Livegang zwingend sind.
 * Was sich nicht automatisch prüfen lässt (z. B. Rechtstexte fachlich geprüft), steht in docs/golive.md.
 */
final class GoLiveCheck
{
    public function __construct(private App $app) {}

    /** @return list<array{group:string, label:string, status:'ok'|'warn'|'error', detail:string}> */
    public function run(): array
    {
        $out = [];
        $add = function (string $label, string $status, string $detail) use (&$out): void {
            $out[] = ['group' => 'Livegang', 'label' => $label, 'status' => $status, 'detail' => $detail];
        };
        $c = $this->app->config;
        $db = $this->app->db;

        // Systemprüfung: alles, was dort Warnung ist, muss vor dem Livegang erledigt sein
        foreach ((new SystemCheck($this->app))->run() as $r) {
            if ($r['status'] === 'warn' && in_array($r['label'], ['Freier Speicher', 'Upload-Grenze', 'Anwendungsschutz', 'Fehlgeschlagene Nachrichten'], true)) {
                $r['detail'] .= ' (Hinweis)';
                $out[] = $r;
                continue;
            }
            if ($r['status'] === 'warn') {
                $r['status'] = 'error';
            }
            $out[] = $r;
        }

        $add('Umgebung', $c->get('APP_ENV') === 'production' ? 'ok' : 'error', 'APP_ENV=' . $c->get('APP_ENV', '–'));
        $paypal = (string) $c->get('PAYPAL_MODE', 'sandbox');
        $add('PayPal-Modus', $paypal === 'live' ? 'ok' : 'error', $paypal === 'live' ? 'live' : 'Sandbox – erst nach erfolgreichem Testkauf auf live stellen');

        $demo = (int) $db->value("SELECT COUNT(*) FROM bookings WHERE JSON_EXTRACT(snapshot_json, '$.demo') = true");
        $add('Beispielbuchungen', $demo === 0 ? 'ok' : 'error', $demo === 0 ? 'keine' : "$demo Beispielbuchungen vorhanden – Datenbank ohne --demo neu aufsetzen");

        $exams = $db->all("SELECT public_name, base_price_cents FROM exam_types WHERE status = 'published'");
        $noPrice = array_column(array_filter($exams, fn($e) => $e['base_price_cents'] === null), 'public_name');
        $add('Veröffentlichte Prüfungen', $exams === [] ? 'error' : ($noPrice === [] ? 'ok' : 'warn'),
            $exams === [] ? 'keine – Prüfungen mit Preis veröffentlichen' : count($exams) . ($noPrice === [] ? ' mit Preis' : ', ohne Grundpreis: ' . implode(', ', $noPrice)));
        $open = (int) $db->value("SELECT COUNT(*) FROM exam_sessions WHERE status = 'open' AND is_visible = 1 AND starts_at > UTC_TIMESTAMP()");
        $add('Buchbare Termine', $open > 0 ? 'ok' : 'warn', $open > 0 ? "$open Termine" : 'keine – Besucher sehen „derzeit keine Termine“');

        foreach (['impressum' => 'Impressum', 'datenschutz' => 'Datenschutzerklärung', 'agb' => 'AGB'] as $slug => $label) {
            $body = (string) $db->value("SELECT body_html FROM pages WHERE slug = ? AND status = 'published'", [$slug]);
            $placeholder = $body === '' || preg_match('/derzeit fachlich erstellt|Platzhalter|Angabe folgt|\[ergänzen\]/iu', $body);
            $add($label, $placeholder ? 'error' : 'ok', $placeholder ? 'enthält noch den Platzhalter – geprüften Text einsetzen' : 'Text vorhanden (fachliche Prüfung siehe Checkliste)');
        }

        $admins = $db->all("SELECT email, totp_enabled_at FROM users WHERE role = 'admin' AND status = 'active'");
        $without = array_filter($admins, fn($a) => $a['totp_enabled_at'] === null);
        $add('Administratoren', count($admins) >= 2 && $without === [] ? 'ok' : (count($admins) >= 1 && $without === [] ? 'warn' : 'error'),
            count($admins) . ' aktiv' . ($without !== [] ? ', ohne 2FA: ' . count($without) : '') . (count($admins) < 2 ? ' – zweite Person als Vertretung empfohlen' : ''));

        $problems = $this->checkOldUrls();
        $add('Alte Adressen', $problems === [] ? 'ok' : 'error', $problems === [] ? 'alle ' . count($this->oldUrls()) . ' führen zu Seite, Weiterleitung oder 410' : implode('; ', array_slice($problems, 0, 5)));

        $missing = (int) $db->value('SELECT COUNT(*) FROM not_found_log WHERE resolved_at IS NULL AND hits >= 3');
        $add('Nicht gefundene Adressen', $missing === 0 ? 'ok' : 'warn', $missing === 0 ? 'keine häufigen' : "$missing Adressen mit 3+ Aufrufen ohne Weiterleitung (Verwaltung → Weiterleitungen)");
        return $out;
    }

    /** @return list<string> */
    private function oldUrls(): array
    {
        $lines = @file($this->app->root . '/docs/migration/alte-urls.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '' && $l[0] === '/'));
    }

    /** Alte Adressen intern aufrufen (ohne Netzwerk). @return list<string> */
    private function checkOldUrls(): array
    {
        $problems = [];
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'MEA-Golive-Check'];
        foreach ($this->oldUrls() as $u) {
            $r = $this->app->handle(new Request('GET', $u, [], [], $server));
            if (in_array($r->status, [301, 302], true)) {
                $target = (string) parse_url((string) ($r->headers['Location'] ?? ''), PHP_URL_PATH);
                $t = $this->app->handle(new Request('GET', $target, [], [], $server));
                if ($t->status !== 200) {
                    $problems[] = "$u → $target ({$t->status})";
                }
            } elseif (!in_array($r->status, [200, 410], true)) {
                $problems[] = "$u ({$r->status})";
            }
        }
        return $problems;
    }
}
