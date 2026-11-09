<?php
declare(strict_types=1);

namespace Mea\Module\Bookings;

use Mea\Core\Database;

/**
 * Welche Teilnehmerdaten eine Prüfung verlangt, ergibt sich aus der Buchungsregel
 * (Standardfelder) und den Zusatzfeldern der Prüfungsart. Kein Feld ist an eine Prüfung fest gebunden.
 */
final class BookingForm
{
    /** Standardfelder, die eine Buchungsregel anfordern kann. */
    public const STANDARD = [
        'salutation' => ['label' => 'Anrede', 'type' => 'select', 'options' => ['' => 'keine Angabe', 'Frau' => 'Frau', 'Herr' => 'Herr', 'Divers' => 'divers'], 'autocomplete' => 'honorific-prefix'],
        'first_name' => ['label' => 'Vorname(n) laut Ausweis', 'type' => 'text', 'max' => 100, 'autocomplete' => 'given-name'],
        'last_name' => ['label' => 'Nachname laut Ausweis', 'type' => 'text', 'max' => 100, 'autocomplete' => 'family-name'],
        'email' => ['label' => 'E-Mail-Adresse', 'type' => 'email', 'max' => 190, 'autocomplete' => 'email', 'help' => 'Hierhin senden wir die Bestätigung.'],
        'phone' => ['label' => 'Telefon', 'type' => 'tel', 'max' => 40, 'autocomplete' => 'tel'],
        'birth_date' => ['label' => 'Geburtsdatum', 'type' => 'date', 'autocomplete' => 'bday'],
        'birth_place' => ['label' => 'Geburtsort', 'type' => 'text', 'max' => 100],
        'address' => ['label' => 'Anschrift', 'type' => 'address'],
    ];

    /** Immer erforderlich, unabhängig von der Regel. */
    private const ALWAYS = ['first_name', 'last_name', 'email'];

    public function __construct(private Database $db) {}

    /** @param array<string,mixed> $rule @return list<string> */
    public static function requiredStandard(?array $rule): array
    {
        $list = json_decode((string) ($rule['required_fields_json'] ?? '[]'), true);
        $list = is_array($list) ? array_values(array_intersect(array_keys(self::STANDARD), $list)) : [];
        return array_values(array_unique(array_merge(self::ALWAYS, $list)));
    }

    /** @return list<array<string,mixed>> */
    public function customFields(int $examId): array
    {
        return array_map(static function (array $f): array {
            $f['options'] = json_decode((string) ($f['options_json'] ?? '[]'), true) ?: [];
            return $f;
        }, $this->db->all('SELECT * FROM exam_form_fields WHERE exam_type_id = ? ORDER BY sort_order, id', [$examId]));
    }

    /**
     * @param array<string,mixed> $in Formulardaten
     * @param list<string> $required Standardfelder
     * @param list<array<string,mixed>> $custom
     * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,string>} [Teilnehmer, Zusatzwerte, Fehler]
     */
    public static function validate(array $in, array $required, array $custom): array
    {
        $p = [];
        $errors = [];
        $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        foreach (['first_name', 'last_name', 'birth_place', 'phone'] as $k) {
            $p[$k] = mb_substr(preg_replace('/\s+/u', ' ', $s($k)) ?? '', 0, (int) (self::STANDARD[$k]['max'] ?? 100));
            if (in_array($k, $required, true) && $p[$k] === '') {
                $errors[$k] = 'Bitte „' . self::STANDARD[$k]['label'] . '“ angeben.';
            }
        }
        $p['salutation'] = in_array($s('salutation'), ['Frau', 'Herr', 'Divers'], true) ? $s('salutation') : null;
        $p['email'] = mb_strtolower($s('email'));
        if (!filter_var($p['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($p['email']) > 190) {
            $errors['email'] = 'Bitte eine gültige E-Mail-Adresse angeben.';
        }
        if ($p['phone'] !== '' && !preg_match('/^[+0-9 ()\/-]{6,40}$/', $p['phone'])) {
            $errors['phone'] = 'Bitte eine gültige Telefonnummer angeben.';
        }
        $p['birth_date'] = null;
        if ($s('birth_date') !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s('birth_date'));
            $age = $d ? (int) $d->diff(new \DateTimeImmutable('today'))->y : -1;
            if ($d === false || $d > new \DateTimeImmutable('today') || $age > 110) {
                $errors['birth_date'] = 'Bitte ein gültiges Geburtsdatum angeben.';
            } else {
                $p['birth_date'] = $d->format('Y-m-d');
            }
        } elseif (in_array('birth_date', $required, true)) {
            $errors['birth_date'] = 'Bitte das Geburtsdatum angeben.';
        }
        $p['street'] = mb_substr($s('street'), 0, 150);
        $p['postal_code'] = mb_substr($s('postal_code'), 0, 10);
        $p['city'] = mb_substr($s('city'), 0, 80);
        if (in_array('address', $required, true)) {
            if ($p['street'] === '') {
                $errors['street'] = 'Bitte Straße und Hausnummer angeben.';
            }
            if (!preg_match('/^[0-9A-Za-z -]{3,10}$/', $p['postal_code'])) {
                $errors['postal_code'] = 'Bitte eine gültige Postleitzahl angeben.';
            }
            if ($p['city'] === '') {
                $errors['city'] = 'Bitte den Ort angeben.';
            }
        }
        foreach (['phone', 'birth_place', 'street', 'postal_code', 'city'] as $k) {
            $p[$k] = $p[$k] === '' ? null : $p[$k];
        }

        $extra = [];
        foreach ($custom as $f) {
            $key = 'x_' . $f['field_key'];
            $v = $s($key);
            $label = (string) $f['label'];
            switch ($f['field_type']) {
                case 'checkbox':
                    $v = $v === '1';
                    if ((int) $f['is_required'] === 1 && !$v) {
                        $errors[$key] = "Bitte „{$label}“ bestätigen.";
                    }
                    break;
                case 'select':
                    $opts = array_map('strval', (array) $f['options']);
                    if ($v !== '' && !in_array($v, $opts, true)) {
                        $errors[$key] = "Bitte für „{$label}“ eine gültige Auswahl treffen.";
                    }
                    break;
                case 'date':
                    if ($v !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $v) === false) {
                        $errors[$key] = "„{$label}“: ungültiges Datum.";
                    }
                    break;
                case 'number':
                    if ($v !== '' && !is_numeric($v)) {
                        $errors[$key] = "„{$label}“ muss eine Zahl sein.";
                    }
                    break;
                default:
                    $v = mb_substr($v, 0, $f['field_type'] === 'textarea' ? 2000 : 255);
            }
            if ($f['field_type'] !== 'checkbox' && (int) $f['is_required'] === 1 && $v === '' && !isset($errors[$key])) {
                $errors[$key] = "Bitte „{$label}“ angeben.";
            }
            $extra[(string) $f['field_key']] = ['label' => $label, 'value' => $v];
        }
        if (($in['terms'] ?? '') !== '1') {
            $errors['terms'] = 'Bitte bestätigen Sie die Teilnahme- und Zahlungsbedingungen.';
        }
        if (($in['privacy'] ?? '') !== '1') {
            $errors['privacy'] = 'Bitte bestätigen Sie die Datenschutzhinweise.';
        }
        return [$p, $extra, $errors];
    }
}
