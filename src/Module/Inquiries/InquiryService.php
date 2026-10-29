<?php
declare(strict_types=1);

namespace Mea\Module\Inquiries;

use Mea\Core\Database;

/** Prüft und speichert Kontaktanfragen. Der Mailversand folgt in Phase 4 über die Warteschlange. */
final class InquiryService
{
    public const TOPICS = [
        'pruefung' => 'Prüfung / telc',
        'sprachkurs' => 'Sprachkurs',
        'avgs' => 'Coaching (AVGS)',
        'fachkraefte' => 'Fachkräftegewinnung / Onboarding',
        'sonstiges' => 'Sonstiges',
    ];

    public function __construct(private Database $db) {}

    /**
     * @param array<string,string> $in
     * @param list<int> $validLocationIds
     * @return array<string,string> Fehler je Feld (leer = gültig)
     */
    public function validate(array $in, array $validLocationIds): array
    {
        $e = [];
        $name = $in['name'] ?? '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $e['name'] = 'Bitte geben Sie Ihren Namen an.';
        }
        if (!filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL) || mb_strlen($in['email']) > 190) {
            $e['email'] = 'Bitte geben Sie eine gültige E-Mail-Adresse an, zum Beispiel name@beispiel.de.';
        }
        $phone = $in['phone'] ?? '';
        if ($phone !== '' && !preg_match('/^[0-9 +()\/-]{6,40}$/', $phone)) {
            $e['phone'] = 'Bitte nur Ziffern, Leerzeichen und + ( ) / - verwenden.';
        }
        if (($in['topic'] ?? '') !== '' && !isset(self::TOPICS[$in['topic']])) {
            $e['topic'] = 'Bitte wählen Sie ein Thema aus der Liste.';
        }
        $loc = $in['location'] ?? '';
        if ($loc !== '' && !in_array((int) $loc, $validLocationIds, true)) {
            $e['location'] = 'Bitte wählen Sie einen Standort aus der Liste.';
        }
        $msg = $in['message'] ?? '';
        if (mb_strlen($msg) < 10 || mb_strlen($msg) > 5000) {
            $e['message'] = 'Bitte schreiben Sie uns Ihr Anliegen (mindestens 10 Zeichen).';
        }
        if (($in['privacy'] ?? '') !== '1') {
            $e['privacy'] = 'Bitte bestätigen Sie die Datenschutzhinweise.';
        }
        return $e;
    }

    /** @param array<string,string> $in */
    public function store(array $in): int
    {
        return $this->db->insert('inquiries', [
            'name' => $in['name'],
            'email' => $in['email'],
            'phone' => ($in['phone'] ?? '') ?: null,
            'topic' => ($in['topic'] ?? '') ?: null,
            'location_id' => ($in['location'] ?? '') === '' ? null : (int) $in['location'],
            'message' => $in['message'],
            'privacy_accepted_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
