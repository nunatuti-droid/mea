<?php
declare(strict_types=1);

namespace Mea\Module\Exams;

use Mea\Core\Database;

/**
 * Öffentliche Sicht auf Prüfungsarten und Termine.
 * Es gibt hier bewusst keine Liste bekannter Prüfungen: alles kommt aus der Datenbank.
 */
final class ExamRepository
{
    /** Status, die eine öffentliche Detailseite haben. */
    public const VISIBLE_STATUSES = ['published', 'disabled'];

    /** Belegte Plätze: bestätigte Buchungen und noch gültige Reservierungen. */
    private const TAKEN_SQL = "(SELECT COUNT(*) FROM bookings b WHERE b.exam_session_id = s.id
        AND (b.status = 'confirmed' OR (b.status IN ('reserved','pending_payment') AND b.reserved_until > UTC_TIMESTAMP())))";

    public function __construct(private Database $db, private PriceCalculator $prices) {}

    /**
     * @param array{provider?:string, level?:string} $filter
     * @return list<array<string,mixed>>
     */
    public function listed(array $filter = []): array
    {
        $sql = "SELECT e.*, p.name AS provider_name FROM exam_types e
                LEFT JOIN exam_providers p ON p.id = e.provider_id
                WHERE e.status IN ('published','disabled') AND e.is_listed = 1";
        $params = [];
        if (($filter['provider'] ?? '') !== '') {
            $sql .= ' AND p.name = ?';
            $params[] = $filter['provider'];
        }
        if (($filter['level'] ?? '') !== '') {
            $sql .= ' AND e.level_label = ?';
            $params[] = $filter['level'];
        }
        $rows = $this->db->all($sql . ' ORDER BY e.sort_order, e.public_name', $params);
        return array_map(fn(array $e) => $this->decorate($e), $rows);
    }

    /** @return array{providers:list<string>, levels:list<string>} */
    public function filterOptions(): array
    {
        return [
            'providers' => array_column($this->db->all(
                "SELECT DISTINCT p.name FROM exam_types e JOIN exam_providers p ON p.id = e.provider_id
                 WHERE e.status IN ('published','disabled') AND e.is_listed = 1 ORDER BY p.name",
            ), 'name'),
            'levels' => array_column($this->db->all(
                "SELECT DISTINCT level_label FROM exam_types
                 WHERE status IN ('published','disabled') AND is_listed = 1 AND level_label IS NOT NULL
                 ORDER BY level_label",
            ), 'level_label'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function bySlug(string $slug, bool $includeDrafts = false): ?array
    {
        $statuses = $includeDrafts ? "'draft','published','disabled'" : "'published','disabled'";
        $e = $this->db->one(
            "SELECT e.*, p.name AS provider_name, p.website AS provider_website FROM exam_types e
             LEFT JOIN exam_providers p ON p.id = e.provider_id
             WHERE e.slug = ? AND e.status IN ($statuses)",
            [$slug],
        );
        return $e === null ? null : $this->decorate($e, true);
    }

    public function isArchived(string $slug): bool
    {
        return $this->db->value("SELECT 1 FROM exam_types WHERE slug = ? AND status = 'archived'", [$slug]) !== null;
    }

    /** @return list<array<string,mixed>> Kommende, sichtbare Termine einer Prüfungsart */
    public function upcomingSessions(int $examId, int $limit = 50): array
    {
        $rows = $this->db->all(
            'SELECT s.*, l.name AS location_name, l.short_name AS location_short, l.city AS location_city, l.slug AS location_slug, ' .
            self::TAKEN_SQL . ' AS seats_taken
             FROM exam_sessions s JOIN locations l ON l.id = s.location_id
             WHERE s.exam_type_id = ? AND s.is_visible = 1 AND s.status IN (\'open\',\'closed\')
               AND s.starts_at > UTC_TIMESTAMP()
             ORDER BY s.starts_at LIMIT ' . max(1, min(200, $limit)),
            [$examId],
        );
        return $rows;
    }

    /** @return list<array<string,mixed>> nächste buchbare Termine über alle Prüfungen (Startseite) */
    public function nextBookableSessions(int $limit = 6): array
    {
        $rows = $this->db->all(
            'SELECT s.*, e.public_name, e.slug AS exam_slug, e.base_price_cents, e.currency, e.status AS exam_status,
                    l.short_name AS location_short, l.city AS location_city, ' . self::TAKEN_SQL . ' AS seats_taken
             FROM exam_sessions s
             JOIN exam_types e ON e.id = s.exam_type_id
             JOIN locations l ON l.id = s.location_id
             WHERE e.status = \'published\' AND s.is_visible = 1 AND s.status = \'open\'
               AND s.registration_until > UTC_TIMESTAMP() AND s.starts_at > UTC_TIMESTAMP()
             ORDER BY s.starts_at LIMIT ' . max(1, min(50, $limit)),
        );
        return array_map(function (array $s): array {
            $s['price'] = $this->prices->calculate(
                ['base_price_cents' => $s['base_price_cents'], 'currency' => $s['currency']],
                $s,
                $this->mandatoryFees((int) $s['exam_type_id']),
            );
            return $this->withAvailability($s, 'published');
        }, $rows);
    }

    /** @return list<array<string,mixed>> */
    public function fees(int $examId): array
    {
        return $this->db->all('SELECT * FROM exam_type_fees WHERE exam_type_id = ? ORDER BY sort_order, id', [$examId]);
    }

    /** @return list<array<string,mixed>> */
    private function mandatoryFees(int $examId): array
    {
        return array_values(array_filter($this->fees($examId), fn($f) => (int) $f['is_mandatory'] === 1));
    }

    /** @return list<array<string,mixed>> */
    public function sitemapEntries(): array
    {
        return $this->db->all("SELECT slug, updated_at FROM exam_types WHERE status IN ('published','disabled')");
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    private function decorate(array $e, bool $withSessions = false): array
    {
        $fees = $this->fees((int) $e['id']);
        $e['fees'] = $fees;
        $e['is_bookable_type'] = $e['status'] === 'published';
        $sessions = $this->upcomingSessions((int) $e['id']);
        $sessions = array_map(function (array $s) use ($e, $fees): array {
            $s['price'] = $this->prices->calculate($e, $s, array_values(array_filter($fees, fn($f) => (int) $f['is_mandatory'] === 1)));
            return $this->withAvailability($s, (string) $e['status']);
        }, $sessions);
        $e['next_session'] = null;
        foreach ($sessions as $s) {
            if ($s['bookable']) {
                $e['next_session'] = $s;
                break;
            }
        }
        $e['from_price'] = $this->prices->calculate($e, null, array_values(array_filter($fees, fn($f) => (int) $f['is_mandatory'] === 1)));
        if ($withSessions) {
            $e['sessions'] = $sessions;
        }
        $e['session_count'] = count(array_filter($sessions, fn($s) => $s['bookable']));
        return $e;
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function withAvailability(array $s, string $examStatus): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $free = max(0, (int) $s['capacity'] - (int) $s['seats_taken']);
        $deadlinePassed = new \DateTimeImmutable((string) $s['registration_until'], new \DateTimeZone('UTC')) <= $now;
        $s['seats_free'] = $free;
        $s['deadline_passed'] = $deadlinePassed;
        $s['bookable'] = $examStatus === 'published' && $s['status'] === 'open' && !$deadlinePassed && $free > 0;
        $s['availability'] = match (true) {
            $examStatus !== 'published' => 'unavailable',
            $s['status'] === 'closed', $deadlinePassed => 'closed',
            $free === 0 => 'full',
            $free <= max(2, (int) ceil((int) $s['capacity'] * 0.2)) => 'few',
            default => 'available',
        };
        $tz = new \DateTimeZone('Europe/Berlin');
        $s['starts_local'] = (new \DateTimeImmutable((string) $s['starts_at'], new \DateTimeZone('UTC')))->setTimezone($tz);
        $s['deadline_local'] = (new \DateTimeImmutable((string) $s['registration_until'], new \DateTimeZone('UTC')))->setTimezone($tz);
        return $s;
    }
}
