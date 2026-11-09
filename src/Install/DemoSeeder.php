<?php
declare(strict_types=1);

namespace Mea\Install;

use Mea\Core\Database;
use Mea\Module\Content\SettingsRepository;

/**
 * NUR für Entwicklung und Vorschau: veröffentlicht die Startprüfungen und legt
 * Beispieltermine mit Beispielpreisen an. Die Website zeigt dann einen deutlichen Hinweis.
 * Auf der Produktivseite wird dieser Seeder nicht ausgeführt.
 */
final class DemoSeeder
{
    public function __construct(private Database $db) {}

    public function run(): void
    {
        (new SettingsRepository($this->db))->set('demo_notice', true);
        $this->db->run("UPDATE exam_types SET status = 'published', published_at = UTC_TIMESTAMP() WHERE status = 'draft'");
        $examples = ['telc-deutsch-a1' => 15000, 'telc-deutsch-a2' => 16000, 'telc-deutsch-a2-b1' => 19000,
            'telc-deutsch-b1' => 18000, 'telc-deutsch-b2' => 22000, 'telc-deutsch-c1' => 26000];
        $locations = array_column($this->db->all('SELECT id, slug FROM locations ORDER BY id'), 'id', 'slug');
        $i = 0;
        foreach ($examples as $slug => $price) {
            $exam = (int) $this->db->value('SELECT id FROM exam_types WHERE slug = ?', [$slug]);
            $this->db->run('UPDATE exam_types SET base_price_cents = ? WHERE id = ?', [$price, $exam]);
            if ($slug === 'telc-deutsch-c1') {
                continue; // zeigt den Zustand „derzeit keine Termine“
            }
            // telc-Prüfungen nur im Prüfungszentrum Herne
            foreach ([[21, 'herne', 16], [49, 'herne', 12]] as $k => [$days, $loc, $cap]) {
                $start = (new \DateTimeImmutable('today 09:00', new \DateTimeZone('Europe/Berlin')))
                    ->modify('+' . ($days + $i * 3) . ' days')->setTimezone(new \DateTimeZone('UTC'));
                $this->db->insert('exam_sessions', [
                    'exam_type_id' => $exam, 'location_id' => $locations[$loc] ?? reset($locations),
                    'starts_at' => $start->format('Y-m-d H:i:s'),
                    'registration_until' => $start->modify('-14 days')->format('Y-m-d H:i:s'),
                    'capacity' => $cap, 'status' => 'open',
                    'notes_public' => 'Beispieltermin für die Vorschau.',
                ]);
            }
            $i++;
        }
        // Ein fast ausgebuchter Termin, damit die Anzeige „wenige Plätze“ sichtbar ist
        $sid = (int) $this->db->value("SELECT s.id FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id WHERE e.slug = 'telc-deutsch-b1' ORDER BY s.starts_at LIMIT 1");
        $exam = (int) $this->db->value("SELECT id FROM exam_types WHERE slug = 'telc-deutsch-b1'");
        $snapshot = json_encode($this->demoSnapshot($sid), JSON_UNESCAPED_UNICODE);
        for ($n = 0; $n < 14; $n++) {
            $bid = $this->db->insert('bookings', [
                'public_ref' => 'DEMO' . str_pad((string) $n, 8, '0', STR_PAD_LEFT), 'exam_session_id' => $sid, 'exam_type_id' => $exam,
                'snapshot_json' => $snapshot, 'total_cents' => 18000, 'currency' => 'EUR',
                'status' => 'confirmed', 'payment_status' => 'paid', 'confirmed_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->db->insert('booking_participants', [
                'booking_id' => $bid, 'first_name' => 'Beispiel', 'last_name' => 'Teilnehmer ' . ($n + 1),
                'email' => 'beispiel' . ($n + 1) . '@example.org',
            ]);
        }
    }

    /** Momentaufnahme im selben Aufbau wie echte Buchungen, damit die Verwaltung sie anzeigen kann. @return array<string,mixed> */
    public function demoSnapshot(int $sessionId): array
    {
        $s = $this->db->one('SELECT s.*, e.slug, e.public_name, e.level_label FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id WHERE s.id = ?', [$sessionId]) ?? [];
        $loc = $this->db->one('SELECT name, short_name, street, address_extra, postal_code, city FROM locations WHERE id = ?', [$s['location_id'] ?? 0]);
        return [
            'demo' => true,
            'exam' => ['id' => (int) ($s['exam_type_id'] ?? 0), 'slug' => $s['slug'] ?? '', 'public_name' => $s['public_name'] ?? '', 'level' => $s['level_label'] ?? null, 'provider' => 'telc', 'format' => null, 'duration' => null],
            'session' => ['id' => $sessionId, 'starts_at' => $s['starts_at'] ?? null, 'ends_at' => null, 'registration_until' => $s['registration_until'] ?? null, 'notes_public' => $s['notes_public'] ?? null, 'location' => $loc],
            'price' => ['base_cents' => 18000, 'fees' => [], 'total_cents' => 18000, 'currency' => 'EUR'],
            'rule' => [], 'terms_version_id' => null, 'booked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
