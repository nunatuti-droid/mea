<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Form;
use Mea\Admin\Crud\Resource;
use Mea\Admin\Labels;
use Mea\Core\Database;
use Mea\Core\Request;
use Mea\Module\Audit\AuditLog;

/** Prüfungstermine: Datum, Ort, Kapazität, Anmeldeschluss, abweichender Preis, Status. */
final class SessionResource extends Resource
{
    public string $key = 'termine';
    public string $table = 'exam_sessions';
    public string $permission = 'sessions';
    public string $singular = 'Termin';
    public string $plural = 'Termine';
    public string $entity = 'exam_session';
    public string $intro = 'Jeder Termin gehört zu genau einer Prüfungsart. Buchungen sind nur bis zum Anmeldeschluss und bis zur Kapazitätsgrenze möglich.';
    public string $orderBy = 'starts_at';

    /** @var \Closure(int,string,string):void */
    private \Closure $notify;

    public function __construct(Database $db, \Closure $notify)
    {
        parent::__construct($db);
        $this->notify = $notify;
    }

    private const TAKEN = "(SELECT COUNT(*) FROM bookings b WHERE b.exam_session_id = s.id AND (b.status = 'confirmed'
        OR (b.status IN ('reserved','pending_payment') AND b.reserved_until > UTC_TIMESTAMP())))";

    public function baseQuery(): string
    {
        return 'SELECT s.*, e.public_name, l.short_name AS location, ' . self::TAKEN . ' AS taken,
            CONCAT(' . self::TAKEN . ', \' / \', s.capacity) AS seats
            FROM exam_sessions s JOIN exam_types e ON e.id = s.exam_type_id JOIN locations l ON l.id = s.location_id';
    }

    public function filters(): array
    {
        return [
            ['name' => 'pruefung', 'label' => 'Prüfung', 'options' => array_column($this->db->all("SELECT id, public_name FROM exam_types WHERE status <> 'archived' ORDER BY sort_order, public_name"), 'public_name', 'id')],
            ['name' => 'zeitraum', 'label' => 'Zeitraum', 'options' => ['kommend' => 'Kommende', 'vergangen' => 'Vergangene']],
            ['name' => 'status', 'label' => 'Status', 'options' => Labels::options('session_status')],
        ];
    }

    public function filterWhere(Request $req): array
    {
        $w = [];
        $p = [];
        if (ctype_digit($req->input('pruefung'))) {
            $w[] = 'exam_type_id = ?';
            $p[] = (int) $req->input('pruefung');
        }
        $z = $req->input('zeitraum', 'kommend');
        if ($z === 'kommend' || $z === '') {
            $w[] = 'starts_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY';
        } elseif ($z === 'vergangen') {
            $w[] = 'starts_at < UTC_TIMESTAMP()';
        }
        if (isset(Labels::MAPS['session_status'][$req->input('status')])) {
            $w[] = 'status = ?';
            $p[] = $req->input('status');
        }
        return [implode(' AND ', $w), $p];
    }

    public function columns(): array
    {
        return [
            ['key' => 'public_name', 'label' => 'Prüfung'],
            ['key' => 'starts_at', 'label' => 'Beginn', 'format' => 'datetime'],
            ['key' => 'location', 'label' => 'Ort'],
            ['key' => 'seats', 'label' => 'Belegt / Plätze'],
            ['key' => 'registration_until', 'label' => 'Anmeldeschluss', 'format' => 'datetime'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status:session_status'],
        ];
    }

    public function titleOf(array $row): string
    {
        $name = $row['public_name'] ?? $this->db->value('SELECT public_name FROM exam_types WHERE id = ?', [$row['exam_type_id'] ?? 0]);
        $when = isset($row['starts_at']) && $row['starts_at'] ? (new \DateTimeImmutable((string) $row['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i') : '';
        return trim($name . ' am ' . $when);
    }

    public function fields(?array $row): array
    {
        $exams = array_column($this->db->all("SELECT id, public_name FROM exam_types WHERE status <> 'archived' OR id = ? ORDER BY sort_order, public_name", [$row['exam_type_id'] ?? 0]), 'public_name', 'id');
        // Prüfungstermine nur an Standorten, die als Prüfungszentrum markiert sind (derzeit Herne)
        $locs = array_column($this->db->all('SELECT id, name FROM locations WHERE (is_active = 1 AND is_exam_center = 1) OR id = ? ORDER BY sort_order', [$row['location_id'] ?? 0]), 'name', 'id');
        $rules = array_column($this->db->all('SELECT id, name FROM booking_rules ORDER BY name'), 'name', 'id');
        $hasBookings = $row !== null && $this->db->value("SELECT 1 FROM bookings WHERE exam_session_id = ? AND status IN ('confirmed','reserved','pending_payment') LIMIT 1", [$row['id']]) !== null;
        $f = [
            ['name' => 'exam_type_id', 'label' => 'Prüfungsart', 'type' => 'select', 'options' => $exams, 'required' => true, 'numeric' => true, 'placeholder' => 'Bitte wählen', 'readonly' => $hasBookings,
                'help' => $hasBookings ? 'Es gibt Buchungen; die Prüfungsart kann nicht mehr geändert werden.' : null],
            ['name' => 'location_id', 'label' => 'Ort', 'type' => 'select', 'options' => $locs, 'required' => true, 'numeric' => true, 'placeholder' => 'Bitte wählen',
                'help' => 'Nur Standorte, die unter „Standorte“ als telc-Prüfungszentrum markiert sind.'],
            ['name' => 'starts_at', 'label' => 'Beginn', 'type' => 'datetime', 'required' => true, 'help' => 'Ortszeit Deutschland.'],
            ['name' => 'ends_at', 'label' => 'Ende (optional)', 'type' => 'datetime'],
            ['name' => 'registration_until', 'label' => 'Anmeldeschluss', 'type' => 'datetime', 'help' => 'Leer lassen = automatisch nach Buchungsregel (z. B. 14 Tage vorher, 23:59 Uhr).'],
            ['name' => 'capacity', 'label' => 'Plätze insgesamt', 'type' => 'int', 'min' => 1, 'maxValue' => 999, 'required' => true],
            ['name' => 'price_cents', 'label' => 'Abweichender Preis', 'type' => 'money', 'help' => 'Leer = Grundpreis der Prüfungsart.'],
            ['name' => 'booking_rule_id', 'label' => 'Abweichende Buchungs-/Stornoregel', 'type' => 'select', 'options' => $rules, 'placeholder' => '– wie Prüfungsart –', 'numeric' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => array_diff_key(Labels::options('session_status'), ['cancelled' => 1]), 'required' => true, 'default' => 'open',
                'help' => 'Absagen über die Schaltfläche „Termin absagen“, damit Teilnehmende informiert werden können.'],
            ['name' => 'is_visible', 'label' => 'Auf der Website anzeigen', 'type' => 'checkbox', 'default' => 1],
            ['name' => 'notes_public', 'label' => 'Hinweis für Teilnehmende', 'type' => 'textarea', 'max' => 1000, 'rows' => 2],
            ['name' => 'notes_internal', 'label' => 'Interne Notiz', 'type' => 'textarea', 'max' => 2000, 'rows' => 2],
        ];
        if ($hasBookings) {
            $f[] = ['name' => 'notify_participants', 'label' => 'Bei Änderung von Datum oder Ort alle Angemeldeten per E-Mail informieren', 'type' => 'checkbox', 'virtual' => true, 'group' => 'Benachrichtigung'];
            $f[] = ['name' => 'change_note', 'label' => 'Nachricht an die Teilnehmenden (optional)', 'type' => 'textarea', 'max' => 1000, 'rows' => 2, 'virtual' => true, 'group' => 'Benachrichtigung'];
        }
        if ($row !== null && $row['status'] === 'cancelled') {
            $f[8]['options'] = Labels::options('session_status');
        }
        return array_map(fn($x) => array_filter($x, fn($v) => $v !== null), $f);
    }

    public function prefill(Request $req): array
    {
        if (ctype_digit($req->input('kopie'))) {
            $src = $this->db->one('SELECT * FROM exam_sessions WHERE id = ?', [(int) $req->input('kopie')]);
            if ($src !== null) {
                unset($src['id'], $src['status'], $src['version']);
                return $src + ['status' => 'open'];
            }
        }
        return ctype_digit($req->input('pruefung')) ? ['exam_type_id' => (int) $req->input('pruefung'), 'capacity' => 16] : ['capacity' => 16];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        if (isset($values['location_id']) && $this->db->value('SELECT is_exam_center FROM locations WHERE id = ?', [(int) $values['location_id']]) != 1) {
            $e['location_id'] = 'Prüfungen finden nur im zertifizierten telc-Prüfungszentrum statt. Bitte diesen Standort wählen.';
        }
        $starts = $values['starts_at'] ?? null;
        if ($starts !== null && $id === null && $starts < gmdate('Y-m-d H:i:s')) {
            $e['starts_at'] = 'Der Beginn liegt in der Vergangenheit.';
        }
        if ($starts !== null && ($values['ends_at'] ?? null) !== null && $values['ends_at'] <= $starts) {
            $e['ends_at'] = 'Das Ende muss nach dem Beginn liegen.';
        }
        if ($starts !== null && ($values['registration_until'] ?? null) !== null && $values['registration_until'] > $starts) {
            $e['registration_until'] = 'Der Anmeldeschluss muss vor dem Beginn liegen.';
        }
        if ($id !== null && isset($values['capacity'])) {
            $taken = (int) $this->db->value("SELECT COUNT(*) FROM bookings WHERE exam_session_id = ? AND (status = 'confirmed' OR (status IN ('reserved','pending_payment') AND reserved_until > UTC_TIMESTAMP()))", [$id]);
            if ((int) $values['capacity'] < $taken) {
                $e['capacity'] = "Es sind bereits $taken Plätze belegt. Die Kapazität kann nicht darunter liegen.";
            }
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        foreach (['booking_rule_id'] as $k) {
            if (($values[$k] ?? '') === '') {
                $values[$k] = null;
            }
        }
        if (($values['registration_until'] ?? null) === null && isset($values['starts_at'])) {
            $ruleId = $values['booking_rule_id'] ?? $this->db->value('SELECT booking_rule_id FROM exam_types WHERE id = ?', [$values['exam_type_id'] ?? $existing['exam_type_id'] ?? 0]);
            $days = (int) ($this->db->value('SELECT registration_days_before FROM booking_rules WHERE id = ?', [$ruleId ?? 0])
                ?? $this->db->value('SELECT registration_days_before FROM booking_rules WHERE is_default = 1 LIMIT 1') ?? 14);
            $local = (new \DateTimeImmutable((string) $values['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->modify("-$days days")->setTime(23, 59);
            $values['registration_until'] = $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($existing !== null) {
            $values['version'] = (int) $existing['version'] + 1;
        }
        return $values;
    }

    public function afterSave(int $id, array $values, ?array $existing, AdminContext $ctx, Request $req): void
    {
        if ($existing === null || $req->input('notify_participants') !== '1') {
            return;
        }
        $changed = ($values['starts_at'] ?? null) !== $existing['starts_at'] || (int) ($values['location_id'] ?? 0) !== (int) $existing['location_id'];
        if ($changed) {
            ($this->notify)($id, 'session_changed', mb_substr($req->input('change_note'), 0, 1000));
        }
    }

    public function deleteBlocker(int $id): ?string
    {
        return $this->db->value('SELECT 1 FROM bookings WHERE exam_session_id = ? LIMIT 1', [$id]) !== null
            ? 'Für diesen Termin gibt es Buchungen. Er kann nicht gelöscht, nur abgesagt werden.' : null;
    }

    public function actions(array $row, AdminContext $ctx): array
    {
        $a = [['label' => 'Teilnehmerliste (CSV)', 'url' => '/verwaltung/termine/' . $row['id'] . '/teilnehmer.csv', 'icon' => 'download']];
        if ($row['status'] !== 'cancelled') {
            $a[] = ['label' => 'Termin absagen', 'url' => '/verwaltung/termine/' . $row['id'] . '/absagen/', 'icon' => 'ban'];
        }
        $a[] = ['label' => 'Als neuen Termin kopieren', 'url' => '/verwaltung/termine/neu/?kopie=' . $row['id'], 'icon' => 'copy'];
        return $a;
    }

    public function panels(int $id, AdminContext $ctx): array
    {
        return [['template' => 'admin/sessions/bookings_panel.twig', 'data' => [
            'session_bookings' => $this->db->all(
                'SELECT b.id, b.public_ref, b.status, b.payment_status, b.total_cents, b.currency, b.created_at, p.first_name, p.last_name, p.email
                 FROM bookings b LEFT JOIN booking_participants p ON p.booking_id = b.id WHERE b.exam_session_id = ? ORDER BY b.created_at', [$id]),
            'can_bookings' => $ctx->can('bookings'),
        ]]];
    }
}
