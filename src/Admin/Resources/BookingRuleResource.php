<?php
declare(strict_types=1);

namespace Mea\Admin\Resources;

use Mea\Admin\AdminContext;
use Mea\Admin\Crud\Resource;
use Mea\Core\Request;
use Mea\Module\Bookings\BookingForm;

final class BookingRuleResource extends Resource
{
    public string $key = 'buchungsregeln';
    public string $table = 'booking_rules';
    public string $permission = 'exams';
    public string $singular = 'Buchungsregel';
    public string $plural = 'Buchungsregeln';
    public string $entity = 'booking_rule';
    public string $intro = 'Anmeldefrist, Reservierungsdauer, Storno- und Erstattungsregeln sowie die abgefragten Teilnehmerdaten. Eine Regel kann für viele Prüfungen gelten.';
    public string $orderBy = 'is_default DESC, name';

    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'registration_days_before', 'label' => 'Anmeldeschluss (Tage vorher)', 'format' => 'int'],
            ['key' => 'reservation_minutes', 'label' => 'Reservierung (Min.)', 'format' => 'int'],
            ['key' => 'is_default', 'label' => 'Standard', 'format' => 'status:bool'],
        ];
    }

    public function fields(?array $row): array
    {
        $std = array_map(fn($d) => $d['label'], BookingForm::STANDARD);
        unset($std['first_name'], $std['last_name'], $std['email']);
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'max' => 120],
            ['name' => 'is_default', 'label' => 'Standardregel für Prüfungen ohne eigene Regel', 'type' => 'checkbox'],
            ['name' => 'registration_days_before', 'label' => 'Anmeldeschluss: Tage vor dem Termin', 'type' => 'int', 'min' => 0, 'maxValue' => 365, 'required' => true, 'default' => 14,
                'help' => 'Wird beim Anlegen eines Termins als Vorschlag für den Anmeldeschluss verwendet.'],
            ['name' => 'reservation_minutes', 'label' => 'Platzreservierung während der Zahlung (Minuten)', 'type' => 'int', 'min' => 5, 'maxValue' => 120, 'required' => true, 'default' => 20],
            ['name' => 'free_cancellation_days', 'label' => 'Kostenlose Stornierung bis (Tage vorher)', 'type' => 'int', 'min' => 0, 'maxValue' => 365, 'group' => 'Stornierung'],
            ['name' => 'cancellation_fee_cents', 'label' => 'Stornogebühr danach (Betrag)', 'type' => 'money', 'group' => 'Stornierung'],
            ['name' => 'cancellation_fee_percent', 'label' => 'oder Stornogebühr in Prozent', 'type' => 'text', 'max' => 6, 'group' => 'Stornierung', 'help' => 'z. B. 25 (für 25 %). Nur eines von beiden ausfüllen.'],
            ['name' => 'refund_allowed', 'label' => 'Erstattung grundsätzlich möglich', 'type' => 'checkbox', 'default' => 1, 'group' => 'Stornierung'],
            ['name' => 'required_fields_json', 'label' => 'Zusätzlich abgefragte Pflichtangaben', 'type' => 'multi', 'options' => $std, 'group' => 'Teilnehmerdaten',
                'help' => 'Vorname, Nachname und E-Mail werden immer abgefragt. Weitere prüfungsspezifische Felder legen Sie bei der Prüfungsart an.'],
            ['name' => 'terms_html', 'label' => 'Teilnahmebedingungen (werden im Anmeldeformular angezeigt)', 'type' => 'html', 'group' => 'Bedingungen',
                'help' => 'Bitte von einer Fachperson prüfen lassen. Jede bestätigte Fassung wird mit der Buchung gespeichert.'],
        ];
    }

    public function validate(array $values, ?int $id): array
    {
        $e = [];
        $p = $values['cancellation_fee_percent'] ?? null;
        if ($p !== null && $p !== '' && (!is_numeric(str_replace(',', '.', (string) $p)) || (float) str_replace(',', '.', (string) $p) > 100)) {
            $e['cancellation_fee_percent'] = 'Bitte eine Zahl zwischen 0 und 100 eingeben.';
        }
        if ($p !== null && $p !== '' && ($values['cancellation_fee_cents'] ?? null) !== null) {
            $e['cancellation_fee_percent'] = 'Bitte entweder einen Betrag oder einen Prozentsatz angeben.';
        }
        return $e;
    }

    public function beforeSave(array $values, ?array $existing, AdminContext $ctx): array
    {
        $p = $values['cancellation_fee_percent'] ?? null;
        $values['cancellation_fee_percent'] = $p === null || $p === '' ? null : (float) str_replace(',', '.', (string) $p);
        return $values;
    }

    public function afterSave(int $id, array $values, ?array $existing, AdminContext $ctx, Request $req): void
    {
        if ((int) ($values['is_default'] ?? 0) === 1) {
            $this->db->run('UPDATE booking_rules SET is_default = 0 WHERE id <> ?', [$id]);
        }
    }

    public function deleteBlocker(int $id): ?string
    {
        if ($this->db->value('SELECT 1 FROM exam_types WHERE booking_rule_id = ? UNION SELECT 1 FROM exam_sessions WHERE booking_rule_id = ? LIMIT 1', [$id, $id]) !== null) {
            return 'Diese Regel wird von Prüfungen oder Terminen verwendet und kann nicht gelöscht werden.';
        }
        return (int) $this->db->value('SELECT is_default FROM booking_rules WHERE id = ?', [$id]) === 1 ? 'Die Standardregel kann nicht gelöscht werden.' : null;
    }
}
