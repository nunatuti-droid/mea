<?php
declare(strict_types=1);

namespace Mea\Module\Bookings;

use Mea\Core\Crypto;
use Mea\Core\Database;
use Mea\Module\Audit\AuditLog;
use Mea\Module\Exams\PriceCalculator;
use Mea\Module\Mail\Mailer;
use Mea\Module\Payments\PaymentException;
use Mea\Module\Payments\PaymentGateway;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Buchungsablauf: Platz reservieren (Zeilensperre gegen Überbuchung), Zahlung anlegen,
 * Zahlung serverseitig bestätigen (Rückleitung oder geprüfter Webhook), stornieren, erstatten.
 * Eine Weiterleitung des Browsers allein bestätigt nie eine Buchung.
 */
final class BookingService
{
    private const TAKEN = "SELECT COUNT(*) FROM bookings WHERE exam_session_id = ? AND id <> ?
        AND (status = 'confirmed' OR (status IN ('reserved','pending_payment') AND reserved_until > ?))";

    public function __construct(
        private Database $db,
        private PriceCalculator $prices,
        private PaymentGateway $gateway,
        private Mailer $mailer,
        private AuditLog $audit,
        private Crypto $crypto,
        private ClockInterface $clock,
        private LoggerInterface $log,
        private string $baseUrl,
    ) {}

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    // ------------------------------------------------------------------
    // Reservierung
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $participant validierte Teilnehmerdaten
     * @param array<string,mixed> $extra Zusatzfelder
     * @param list<int> $chosenFees
     * @return array{id:int, ref:string, key:string, total_cents:int}
     */
    public function reserve(int $examId, int $sessionId, array $participant, array $extra, array $chosenFees): array
    {
        $key = Crypto::token();
        $result = $this->db->transaction(function () use ($examId, $sessionId, $participant, $extra, $chosenFees, $key): array {
            // Sperre auf dem Termin: parallele Buchungen warten hier und zählen danach neu
            $s = $this->db->one('SELECT * FROM exam_sessions WHERE id = ? AND exam_type_id = ? FOR UPDATE', [$sessionId, $examId]);
            $e = $this->db->one('SELECT e.*, p.name AS provider_name FROM exam_types e LEFT JOIN exam_providers p ON p.id = e.provider_id WHERE e.id = ?', [$examId]);
            if ($s === null || $e === null) {
                throw new BookingException('Dieser Termin existiert nicht.', [], 'not_found');
            }
            $now = $this->now();
            if ($e['status'] !== 'published') {
                throw new BookingException('Diese Prüfung kann derzeit nicht gebucht werden.', [], 'disabled');
            }
            if ($s['status'] !== 'open' || (int) $s['is_visible'] !== 1 || $s['registration_until'] <= $now || $s['starts_at'] <= $now) {
                throw new BookingException('Für diesen Termin ist keine Anmeldung mehr möglich.', [], 'closed');
            }
            $taken = (int) $this->db->value(self::TAKEN, [$sessionId, 0, $now]);
            if ($taken >= (int) $s['capacity']) {
                throw new BookingException('Dieser Termin ist leider ausgebucht.', [], 'full');
            }
            $rule = $this->ruleFor($e, $s);
            $fees = $this->db->all('SELECT * FROM exam_type_fees WHERE exam_type_id = ? ORDER BY sort_order, id', [$examId]);
            $validChosen = array_values(array_intersect($chosenFees, array_map(fn($f) => (int) $f['id'], $fees)));
            $price = $this->prices->calculate($e, $s, $fees, $validChosen);
            if ($price === null) {
                throw new BookingException('Für diesen Termin ist noch kein Preis hinterlegt.', [], 'no_price');
            }
            $location = $this->db->one('SELECT name, short_name, street, address_extra, postal_code, city FROM locations WHERE id = ?', [$s['location_id']]);
            $terms = $this->termsVersion($rule);
            $minutes = max(5, (int) ($rule['reservation_minutes'] ?? 20));
            $ref = $this->newReference();
            $snapshot = $this->snapshot($e, $s, $location, $price, $rule, $terms, $now);
            $id = $this->db->insert('bookings', [
                'public_ref' => $ref, 'access_key_hash' => $this->crypto->hash($key, 'booking'),
                'exam_session_id' => $sessionId, 'exam_type_id' => $examId,
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'total_cents' => $price['total_cents'], 'currency' => $price['currency'],
                'terms_version_id' => $terms, 'privacy_accepted_at' => $now,
                'status' => 'reserved', 'payment_status' => 'none',
                'reserved_until' => $this->clock->now()->modify("+$minutes minutes")->format('Y-m-d H:i:s'),
            ]);
            $this->db->insert('booking_participants', $participant + [
                'booking_id' => $id,
                'extra_json' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
            ]);
            return ['id' => $id, 'ref' => $ref, 'total_cents' => (int) $price['total_cents']];
        });
        $this->audit->record('booking.reserved', 'Platz reserviert: ' . $result['ref'], 'booking', $result['id'], [], null);
        return $result + ['key' => $key];
    }

    /**
     * Momentaufnahme der Buchung (Name, Termin, Preis, Regeln zum Buchungszeitpunkt).
     * @param array<string,mixed> $e @param array<string,mixed> $s @param array<string,mixed>|null $location
     * @param array<string,mixed> $price @param array<string,mixed> $rule @return array<string,mixed>
     */
    private function snapshot(array $e, array $s, ?array $location, array $price, array $rule, ?int $terms, string $now): array
    {
        return [
            'exam' => [
                'id' => (int) $e['id'], 'slug' => $e['slug'], 'public_name' => $e['public_name'], 'level' => $e['level_label'],
                'provider' => $e['provider_name'], 'format' => $e['exam_format'], 'duration' => $e['duration_text'],
            ],
            'session' => [
                'id' => (int) $s['id'], 'starts_at' => $s['starts_at'], 'ends_at' => $s['ends_at'],
                'registration_until' => $s['registration_until'], 'notes_public' => $s['notes_public'],
                'location' => $location,
            ],
            'price' => $price,
            'rule' => [
                'id' => $rule['id'] ?? null, 'name' => $rule['name'] ?? null,
                'free_cancellation_days' => $rule['free_cancellation_days'] ?? null,
                'cancellation_fee_cents' => $rule['cancellation_fee_cents'] ?? null,
                'cancellation_fee_percent' => $rule['cancellation_fee_percent'] ?? null,
                'refund_allowed' => (int) ($rule['refund_allowed'] ?? 1),
            ],
            'terms_version_id' => $terms,
            'booked_at' => $now,
        ];
    }

    /**
     * Buchung aus dem alten Shop (oder telefonisch/bar) übernehmen: sofort bestätigt, Platz wird gezählt.
     * Mit Zahlungsreferenz wird die Zahlung als extern eingegangen vermerkt; dieselbe Referenz kann nur einmal übernommen werden.
     * @param array<string,mixed> $participant
     * @return array{id:int, ref:string}
     */
    public function importExternal(int $sessionId, array $participant, int $amountCents, bool $paid, string $externalRef, string $note, int $actor, bool $sendConfirmation): array
    {
        $externalRef = trim($externalRef);
        $result = $this->db->transaction(function () use ($sessionId, $participant, $amountCents, $paid, $externalRef, $note): array {
            $s = $this->db->one('SELECT * FROM exam_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
            if ($s === null) {
                throw new BookingException('Dieser Termin existiert nicht.', [], 'not_found');
            }
            $e = $this->db->one('SELECT e.*, p.name AS provider_name FROM exam_types e LEFT JOIN exam_providers p ON p.id = e.provider_id WHERE e.id = ?', [$s['exam_type_id']]);
            if ($s['status'] === 'cancelled') {
                throw new BookingException('Der Termin ist abgesagt.', [], 'closed');
            }
            if ($externalRef !== '' && $this->db->value("SELECT 1 FROM payments WHERE provider = 'extern' AND provider_order_id = ?", [$externalRef]) !== null) {
                throw new BookingException("Die Referenz $externalRef wurde bereits übernommen.", [], 'duplicate');
            }
            $now = $this->now();
            if ((int) $this->db->value(self::TAKEN, [$sessionId, 0, $now]) >= (int) $s['capacity']) {
                throw new BookingException('Der Termin ist voll. Bitte vorher die Teilnehmerzahl erhöhen.', [], 'full');
            }
            $rule = $this->ruleFor($e, $s);
            $location = $this->db->one('SELECT name, short_name, street, address_extra, postal_code, city FROM locations WHERE id = ?', [$s['location_id']]);
            $price = ['base_cents' => $amountCents, 'fees' => [], 'total_cents' => $amountCents, 'currency' => 'EUR', 'source' => 'übernommen'];
            $ref = $this->newReference();
            $snapshot = $this->snapshot($e, $s, $location, $price, $rule, null, $now);
            $id = $this->db->insert('bookings', [
                'public_ref' => $ref, 'access_key_hash' => null, 'exam_session_id' => $sessionId, 'exam_type_id' => (int) $s['exam_type_id'],
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'total_cents' => $amountCents, 'currency' => 'EUR', 'status' => 'confirmed', 'confirmed_at' => $now,
                'payment_status' => $paid ? 'paid' : 'none',
                'internal_note' => mb_substr(trim('Übernommen' . ($externalRef !== '' ? " (Referenz $externalRef)" : '') . '. ' . $note), 0, 2000),
            ]);
            $this->db->insert('booking_participants', $participant + ['booking_id' => $id]);
            if ($paid) {
                $this->db->insert('payments', ['booking_id' => $id, 'provider' => 'extern', 'mode' => 'live',
                    'provider_order_id' => $externalRef !== '' ? mb_substr($externalRef, 0, 64) : 'manuell-' . $ref,
                    'amount_cents' => $amountCents, 'currency' => 'EUR', 'status' => 'captured', 'captured_at' => $now]);
            }
            return ['id' => $id, 'ref' => $ref];
        });
        $this->audit->record('booking.imported', 'Buchung übernommen: ' . $result['ref'] . ($externalRef !== '' ? " ($externalRef)" : ''), 'booking', $result['id'], [], $actor);
        if ($sendConfirmation) {
            $this->sendConfirmation($result['id']);
        }
        return $result;
    }

    /** @param array<string,mixed> $exam @param array<string,mixed> $session @return array<string,mixed> */
    private function ruleFor(array $exam, array $session): array
    {
        $id = $session['booking_rule_id'] ?? $exam['booking_rule_id'] ?? null;
        $rule = $id !== null ? $this->db->one('SELECT * FROM booking_rules WHERE id = ?', [$id]) : null;
        return $rule ?? $this->db->one('SELECT * FROM booking_rules WHERE is_default = 1 ORDER BY id LIMIT 1') ?? [];
    }

    /** @param array<string,mixed> $exam @param array<string,mixed> $session @return array<string,mixed> */
    public function rule(array $exam, array $session): array
    {
        return $this->ruleFor($exam, $session);
    }

    /** Bestätigte Bedingungen versionieren: AGB-Seite + Regeltext, Version per SHA-256. */
    private function termsVersion(array $rule): ?int
    {
        $agb = $this->db->one("SELECT title, body_html FROM pages WHERE slug = 'agb' AND status = 'published'");
        $body = (string) ($agb['body_html'] ?? '') . "\n<!-- Regel -->\n" . (string) ($rule['terms_html'] ?? '');
        if (trim(strip_tags($body)) === '') {
            return null;
        }
        $hash = hash('sha256', $body);
        $id = $this->db->value('SELECT id FROM terms_versions WHERE sha256 = ?', [$hash]);
        if ($id !== null) {
            return (int) $id;
        }
        return $this->db->insert('terms_versions', [
            'title' => 'AGB und Prüfungsbedingungen' . (isset($rule['name']) ? ' (' . $rule['name'] . ')' : ''),
            'body_html' => $body, 'sha256' => $hash, 'valid_from' => $this->now(),
        ]);
    }

    private function newReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $r = 'MEA-';
            for ($i = 0; $i < 8; $i++) {
                $r .= $alphabet[random_int(0, 31)];
            }
        } while ($this->db->value('SELECT 1 FROM bookings WHERE public_ref = ?', [$r]) !== null);
        return $r;
    }

    // ------------------------------------------------------------------
    // Zugriff für Teilnehmende (Status-Seite)
    // ------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function findForCustomer(string $ref, string $key): ?array
    {
        $b = $this->db->one('SELECT * FROM bookings WHERE public_ref = ?', [$ref]);
        if ($b === null || $key === '' || !hash_equals((string) $b['access_key_hash'], $this->crypto->hash($key, 'booking'))) {
            return null;
        }
        return $this->hydrate($b);
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    public function hydrate(array $b): array
    {
        $b['snapshot'] = json_decode((string) $b['snapshot_json'], true) ?: [];
        $b['participant'] = $this->db->one('SELECT * FROM booking_participants WHERE booking_id = ?', [$b['id']]);
        $b['payments'] = $this->db->all('SELECT * FROM payments WHERE booking_id = ? ORDER BY id', [$b['id']]);
        $b['refunds'] = $this->db->all('SELECT r.*, u.name AS user_name FROM refunds r JOIN payments p ON p.id = r.payment_id LEFT JOIN users u ON u.id = r.created_by WHERE p.booking_id = ? ORDER BY r.id', [$b['id']]);
        $b['starts_local'] = isset($b['snapshot']['session']['starts_at'])
            ? (new \DateTimeImmutable($b['snapshot']['session']['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin')) : null;
        return $b;
    }

    public function statusUrl(string $ref, string $key): string
    {
        return rtrim($this->baseUrl, '/') . '/buchung/' . rawurlencode($ref) . '/?k=' . rawurlencode($key);
    }

    // ------------------------------------------------------------------
    // Zahlung
    // ------------------------------------------------------------------

    /**
     * Zahlungsvorgang anlegen und den Link zu PayPal liefern.
     * Kostenlose Buchungen werden ohne Zahlung bestätigt (Rückgabe null).
     */
    public function startPayment(int $bookingId, string $key): ?string
    {
        $b = $this->db->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if ($b === null || !in_array($b['status'], ['reserved', 'pending_payment'], true)) {
            throw new BookingException('Diese Buchung kann nicht mehr bezahlt werden.', [], 'state');
        }
        if ((int) $b['total_cents'] === 0) {
            $this->confirm($bookingId, null, 'free');
            return null;
        }
        if (!$this->gateway->isConfigured()) {
            throw new BookingException('Die Online-Zahlung ist derzeit nicht verfügbar. Bitte melden Sie sich telefonisch oder per Nachricht an.', [], 'no_gateway');
        }
        $existing = $this->db->one("SELECT * FROM payments WHERE booking_id = ? AND status IN ('created','approved') ORDER BY id DESC LIMIT 1", [$bookingId]);
        $snapshot = json_decode((string) $b['snapshot_json'], true);
        $ref = (string) $b['public_ref'];
        $return = rtrim($this->baseUrl, '/') . '/buchung/' . rawurlencode($ref) . '/zahlung/?k=' . rawurlencode($key);
        $cancel = rtrim($this->baseUrl, '/') . '/buchung/' . rawurlencode($ref) . '/abbruch/?k=' . rawurlencode($key);
        try {
            $order = $this->gateway->createOrder(
                (int) $b['total_cents'], (string) $b['currency'], $ref,
                'Prüfungsgebühr ' . ($snapshot['exam']['public_name'] ?? '') . ' ' . $ref,
                $return, $cancel, 'order-' . $ref . '-' . ($existing ? $existing['id'] : '0'),
            );
        } catch (PaymentException $e) {
            $this->log->error('PayPal createOrder', ['ref' => $ref, 'error' => $e->getMessage()]);
            throw new BookingException('Die Verbindung zu PayPal ist fehlgeschlagen. Bitte versuchen Sie es in einigen Minuten erneut.', [], 'gateway_error');
        }
        $this->db->transaction(function () use ($b, $order, $bookingId): void {
            $this->db->run(
                'INSERT INTO payments (booking_id, provider, mode, provider_order_id, amount_cents, currency, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'created\') ON DUPLICATE KEY UPDATE updated_at = updated_at',
                [$bookingId, $this->gateway->name(), $this->gateway->mode(), $order['order_id'], $b['total_cents'], $b['currency']],
            );
            // Zeit für die Zahlung bei PayPal; der Platz bleibt so lange reserviert
            $minutes = 30;
            $until = $this->clock->now()->modify("+$minutes minutes")->format('Y-m-d H:i:s');
            $this->db->run(
                "UPDATE bookings SET status = 'pending_payment', payment_status = 'pending', reserved_until = GREATEST(COALESCE(reserved_until, ?), ?) WHERE id = ? AND status IN ('reserved','pending_payment')",
                [$until, $until, $bookingId],
            );
        });
        return $order['approve_url'];
    }

    /**
     * Rückkehr von PayPal: Zahlung serverseitig einziehen und prüfen.
     * @return 'confirmed'|'pending'|'failed'|'refunded_full'
     */
    public function completeReturn(string $ref, string $orderId): string
    {
        $p = $this->db->one('SELECT p.* FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.public_ref = ? AND p.provider_order_id = ?', [$ref, $orderId]);
        if ($p === null) {
            return 'failed';
        }
        return $this->captureAndConfirm($p);
    }

    /** @param array<string,mixed> $p @return 'confirmed'|'pending'|'failed'|'refunded_full' */
    private function captureAndConfirm(array $p): string
    {
        if ($p['status'] === 'captured') {
            $b = $this->db->one('SELECT status FROM bookings WHERE id = ?', [$p['booking_id']]);
            return ($b['status'] ?? '') === 'confirmed' ? 'confirmed' : 'refunded_full';
        }
        try {
            $cap = $this->gateway->captureOrder((string) $p['provider_order_id'], 'capture-' . $p['provider_order_id']);
        } catch (PaymentException $e) {
            $this->log->warning('PayPal capture', ['payment' => $p['id'], 'error' => $e->getMessage()]);
            $this->db->run("UPDATE payments SET last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 255), $p['id']]);
            return 'failed';
        }
        if ($cap['status'] === 'COMPLETED' && $cap['capture_id'] !== null) {
            return $this->paymentCaptured((int) $p['id'], $cap['capture_id'], (int) $cap['amount_cents'], (string) $cap['currency'], 'return', $cap['payer_email'] ?? null);
        }
        if ($cap['status'] === 'PENDING') {
            $this->db->run("UPDATE payments SET status = 'approved', provider_capture_id = COALESCE(provider_capture_id, ?) WHERE id = ?", [$cap['capture_id'], $p['id']]);
            return 'pending';
        }
        $this->db->run("UPDATE payments SET status = 'failed', last_error = ? WHERE id = ?", ['Status ' . $cap['status'], $p['id']]);
        $this->db->run("UPDATE bookings SET payment_status = 'failed' WHERE id = ? AND status = 'pending_payment'", [$p['booking_id']]);
        return 'failed';
    }

    /**
     * Eingezogene Zahlung verbuchen (idempotent, auch bei mehrfachen Ereignissen).
     * Betrag und Währung müssen exakt passen. Ist die Reservierung inzwischen abgelaufen und
     * der Termin voll, wird automatisch erstattet.
     * @return 'confirmed'|'refunded_full'|'failed'
     */
    public function paymentCaptured(int $paymentId, string $captureId, int $amountCents, string $currency, string $source, ?string $payerEmail = null): string
    {
        $outcome = $this->db->transaction(function () use ($paymentId, $captureId, $amountCents, $currency, $source, $payerEmail): string {
            $p = $this->db->one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
            if ($p === null) {
                return 'failed';
            }
            // Sperrreihenfolge: Termin, dann Buchung (wie bei der Reservierung)
            $sessionId = (int) $this->db->value('SELECT exam_session_id FROM bookings WHERE id = ?', [$p['booking_id']]);
            $s = $this->db->one('SELECT * FROM exam_sessions WHERE id = ? FOR UPDATE', [$sessionId]);
            $b = $this->db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$p['booking_id']]);
            if ($b === null || $s === null) {
                return 'failed';
            }
            if ($b['status'] === 'confirmed') {
                return 'confirmed'; // schon verbucht
            }
            if ($amountCents !== (int) $p['amount_cents'] || strtoupper($currency) !== strtoupper((string) $p['currency'])) {
                $this->db->run("UPDATE payments SET status = 'captured', provider_capture_id = ?, captured_at = ?, last_error = 'Betrag weicht ab' WHERE id = ?", [$captureId, $this->now(), $paymentId]);
                $this->audit->record('payment.mismatch', 'Zahlungsbetrag weicht ab: ' . $b['public_ref'], 'booking', (int) $b['id'], ['erwartet' => $p['amount_cents'], 'erhalten' => $amountCents]);
                return 'failed';
            }
            $this->db->run(
                "UPDATE payments SET status = 'captured', provider_capture_id = ?, captured_at = ?, payer_email_hash = ?, last_error = NULL WHERE id = ?",
                [$captureId, $this->now(), $payerEmail ? $this->crypto->hash(mb_strtolower($payerEmail), 'email') : null, $paymentId],
            );
            $now = $this->now();
            $stillReserved = in_array($b['status'], ['reserved', 'pending_payment'], true) && $b['reserved_until'] > $now;
            $taken = (int) $this->db->value(self::TAKEN, [$sessionId, (int) $b['id'], $now]);
            $canConfirm = $stillReserved || ($taken < (int) $s['capacity'] && $s['status'] !== 'cancelled');
            if (!$canConfirm) {
                $this->db->run("UPDATE bookings SET status = 'cancelled', payment_status = 'paid', cancelled_at = ?, cancel_reason = ? WHERE id = ?",
                    [$now, 'Zahlung nach Ablauf der Reservierung eingegangen, Termin inzwischen voll', $b['id']]);
                return 'refund_needed';
            }
            $this->db->run("UPDATE bookings SET status = 'confirmed', payment_status = 'paid', confirmed_at = ?, reserved_until = NULL WHERE id = ?", [$now, $b['id']]);
            $this->audit->record('booking.confirmed', 'Buchung bestätigt (' . $source . '): ' . $b['public_ref'], 'booking', (int) $b['id'], [], null);
            return 'confirmed';
        });
        $bookingId = (int) $this->db->value('SELECT booking_id FROM payments WHERE id = ?', [$paymentId]);
        if ($outcome === 'confirmed') {
            $this->sendConfirmation($bookingId);
        } elseif ($outcome === 'refund_needed') {
            $this->refund($bookingId, null, 'Automatische Erstattung: Termin ausgebucht', null);
            $this->notifyCustomer($bookingId, 'booking_cancelled', 'Ihre Zahlung ist nach Ablauf der Reservierung eingegangen. Der Termin war inzwischen ausgebucht. Wir haben den Betrag vollständig erstattet.');
            return 'refunded_full';
        }
        return $outcome === 'refund_needed' ? 'refunded_full' : $outcome;
    }

    /** Bestätigung ohne Zahlung (kostenlose Prüfung) oder durch Verwaltung. */
    public function confirm(int $bookingId, ?int $actor, string $source): void
    {
        $this->db->transaction(function () use ($bookingId, $source, $actor): void {
            $b = $this->db->one('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            if ($b === null || $b['status'] === 'confirmed') {
                return;
            }
            $this->db->run("UPDATE bookings SET status = 'confirmed', confirmed_at = ?, reserved_until = NULL WHERE id = ?", [$this->now(), $bookingId]);
            $this->audit->record('booking.confirmed', 'Buchung bestätigt (' . $source . '): ' . $b['public_ref'], 'booking', $bookingId, [], $actor);
        });
        $this->sendConfirmation($bookingId);
    }

    /** Abbruch bei PayPal durch die Kundin oder den Kunden: Platz freigeben. */
    public function cancelUnpaid(int $bookingId, string $reason): void
    {
        $n = $this->db->run(
            "UPDATE bookings SET status = 'cancelled', payment_status = IF(payment_status = 'pending', 'none', payment_status), cancelled_at = ?, cancel_reason = ?, reserved_until = NULL
             WHERE id = ? AND status IN ('reserved','pending_payment') AND payment_status <> 'paid'",
            [$this->now(), $reason, $bookingId],
        );
        if ($n === 1) {
            $this->db->run("UPDATE payments SET status = 'cancelled' WHERE booking_id = ? AND status IN ('created','approved')", [$bookingId]);
            $this->audit->record('booking.cancelled', 'Reservierung abgebrochen', 'booking', $bookingId, ['grund' => $reason], null);
        }
    }

    // ------------------------------------------------------------------
    // Webhook
    // ------------------------------------------------------------------

    /**
     * @param array<string,string> $headers kleingeschriebene Header
     * @return array{status:int, result:string}
     */
    public function handleWebhook(array $headers, string $raw): array
    {
        $event = json_decode($raw, true);
        if (!is_array($event) || !isset($event['id'], $event['event_type'])) {
            return ['status' => 400, 'result' => 'invalid'];
        }
        if (!$this->gateway->verifyWebhook($headers, $raw)) {
            $this->log->warning('PayPal-Webhook mit ungültiger Signatur', ['event' => mb_substr((string) $event['id'], 0, 100)]);
            return ['status' => 400, 'result' => 'signature'];
        }
        $type = (string) $event['event_type'];
        $resource = (array) ($event['resource'] ?? []);
        try {
            $eventRow = $this->db->insert('payment_events', [
                'provider' => $this->gateway->name(), 'event_id' => mb_substr((string) $event['id'], 0, 100),
                'event_type' => mb_substr($type, 0, 80), 'resource_id' => isset($resource['id']) ? mb_substr((string) $resource['id'], 0, 64) : null,
                'payload_json' => $raw, 'verified' => 1,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['status' => 200, 'result' => 'duplicate']; // schon verarbeitet
            }
            throw $e;
        }
        $result = $this->processEvent($type, $resource);
        $this->db->run('UPDATE payment_events SET processed_at = ?, result = ? WHERE id = ?', [$this->now(), $result, $eventRow]);
        return ['status' => 200, 'result' => $result];
    }

    /** @param array<string,mixed> $r */
    private function processEvent(string $type, array $r): string
    {
        switch ($type) {
            case 'CHECKOUT.ORDER.APPROVED':
                $p = $this->db->one('SELECT * FROM payments WHERE provider = ? AND provider_order_id = ?', [$this->gateway->name(), (string) ($r['id'] ?? '')]);
                return $p === null ? 'unknown_order' : $this->captureAndConfirm($p);
            case 'PAYMENT.CAPTURE.COMPLETED':
                $orderId = (string) ($r['supplementary_data']['related_ids']['order_id'] ?? '');
                $p = $this->db->one('SELECT * FROM payments WHERE provider = ? AND provider_order_id = ?', [$this->gateway->name(), $orderId]);
                if ($p === null) {
                    return 'unknown_order';
                }
                $amount = (int) round(((float) ($r['amount']['value'] ?? 0)) * 100);
                return $this->paymentCaptured((int) $p['id'], (string) $r['id'], $amount, (string) ($r['amount']['currency_code'] ?? ''), 'webhook');
            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.DECLINED':
                $orderId = (string) ($r['supplementary_data']['related_ids']['order_id'] ?? '');
                $n = $this->db->run("UPDATE payments SET status = 'failed', last_error = ? WHERE provider_order_id = ? AND status <> 'captured'", [$type, $orderId]);
                $this->db->run("UPDATE bookings b JOIN payments p ON p.booking_id = b.id SET b.payment_status = 'failed' WHERE p.provider_order_id = ? AND b.status = 'pending_payment'", [$orderId]);
                return $n > 0 ? 'failed_recorded' : 'ignored';
            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                $captureId = '';
                foreach ((array) ($r['links'] ?? []) as $l) {
                    if (($l['rel'] ?? '') === 'up' && preg_match('#/captures/([^/]+)$#', (string) $l['href'], $m)) {
                        $captureId = $m[1];
                    }
                }
                $p = $this->db->one('SELECT * FROM payments WHERE provider_capture_id = ?', [$captureId]);
                if ($p === null) {
                    return 'unknown_capture';
                }
                $refundId = (string) ($r['id'] ?? '');
                if ($this->db->value('SELECT 1 FROM refunds WHERE provider_refund_id = ?', [$refundId]) === null) {
                    $amount = (int) round(((float) ($r['amount']['value'] ?? 0)) * 100);
                    $this->db->insert('refunds', ['payment_id' => $p['id'], 'provider_refund_id' => $refundId, 'amount_cents' => $amount,
                        'reason' => $type === 'PAYMENT.CAPTURE.REVERSED' ? 'Rückbuchung (Käuferschutz/Bank)' : 'Erstattung über PayPal', 'status' => 'completed']);
                    $this->applyRefundTotals((int) $p['id']);
                    $this->audit->record('payment.refund_external', 'Erstattung/Rückbuchung von PayPal gemeldet', 'booking', (int) $p['booking_id']);
                }
                return 'refund_recorded';
            default:
                return 'ignored';
        }
    }

    // ------------------------------------------------------------------
    // Verwaltung: Stornieren und Erstatten
    // ------------------------------------------------------------------

    /**
     * @param int|null $amountCents null = Restbetrag vollständig erstatten
     * @return int erstatteter Betrag in Cent
     */
    public function refund(int $bookingId, ?int $amountCents, string $reason, ?int $actor): int
    {
        $p = $this->db->one("SELECT * FROM payments WHERE booking_id = ? AND status IN ('captured','partially_refunded') ORDER BY id DESC LIMIT 1", [$bookingId]);
        if ($p !== null && $p['provider'] === 'extern') {
            throw new BookingException('Diese Zahlung lief nicht über PayPal auf dieser Website (z. B. alter Shop). Bitte dort erstatten und hier nur stornieren.');
        }
        if ($p === null || $p['provider_capture_id'] === null) {
            throw new BookingException('Für diese Buchung gibt es keine eingezogene Zahlung.');
        }
        $open = (int) $p['amount_cents'] - (int) $p['refunded_cents'];
        $amount = $amountCents ?? $open;
        if ($amount <= 0 || $amount > $open) {
            throw new BookingException('Der Erstattungsbetrag muss zwischen 0,01 € und dem noch offenen Betrag liegen.');
        }
        $count = (int) $this->db->value('SELECT COUNT(*) FROM refunds WHERE payment_id = ?', [$p['id']]);
        $refundRow = $this->db->insert('refunds', ['payment_id' => $p['id'], 'amount_cents' => $amount, 'reason' => mb_substr($reason, 0, 255), 'status' => 'pending', 'created_by' => $actor]);
        try {
            $res = $this->gateway->refund((string) $p['provider_capture_id'], $amount, (string) $p['currency'], $reason, 'refund-' . $p['id'] . '-' . ($count + 1));
        } catch (PaymentException $e) {
            $this->db->run("UPDATE refunds SET status = 'failed' WHERE id = ?", [$refundRow]);
            $this->audit->record('payment.refund_failed', 'Erstattung fehlgeschlagen', 'booking', $bookingId, ['fehler' => $e->getMessage()], $actor);
            throw new BookingException('PayPal hat die Erstattung abgelehnt: ' . $e->getMessage());
        }
        $status = in_array($res['status'], ['COMPLETED', 'PENDING'], true) ? 'completed' : 'failed';
        $this->db->run('UPDATE refunds SET status = ?, provider_refund_id = ? WHERE id = ?', [$status, $res['refund_id'] ?: null, $refundRow]);
        if ($status !== 'completed') {
            throw new BookingException('Die Erstattung wurde nicht ausgeführt (Status ' . $res['status'] . ').');
        }
        $this->applyRefundTotals((int) $p['id']);
        $this->audit->record('payment.refunded', 'Erstattet: ' . number_format($amount / 100, 2, ',', '.') . ' €', 'booking', $bookingId, ['grund' => $reason], $actor);
        return $amount;
    }

    private function applyRefundTotals(int $paymentId): void
    {
        $sum = (int) $this->db->value("SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE payment_id = ? AND status = 'completed'", [$paymentId]);
        $p = $this->db->one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if ($p === null) {
            return;
        }
        $full = $sum >= (int) $p['amount_cents'];
        $this->db->run('UPDATE payments SET refunded_cents = ?, status = ? WHERE id = ?', [$sum, $full ? 'refunded' : 'partially_refunded', $paymentId]);
        $this->db->run(
            "UPDATE bookings SET payment_status = ?, status = IF(? AND status = 'cancelled', 'refunded', status) WHERE id = ?",
            [$full ? 'refunded' : 'partially_refunded', $full ? 1 : 0, $p['booking_id']],
        );
    }

    /** Stornierung durch die Verwaltung; Erstattung und E-Mail optional. */
    public function cancelByAdmin(int $bookingId, string $reason, ?int $refundCents, bool $refund, bool $notify, int $actor): void
    {
        $b = $this->db->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if ($b === null || in_array($b['status'], ['cancelled', 'expired', 'refunded'], true)) {
            throw new BookingException('Diese Buchung ist bereits storniert oder abgelaufen.');
        }
        $this->db->run("UPDATE bookings SET status = 'cancelled', cancelled_at = ?, cancel_reason = ?, reserved_until = NULL WHERE id = ?", [$this->now(), mb_substr($reason, 0, 255), $bookingId]);
        $this->db->run("UPDATE payments SET status = 'cancelled' WHERE booking_id = ? AND status IN ('created','approved')", [$bookingId]);
        $this->audit->record('booking.cancelled', 'Buchung storniert: ' . $b['public_ref'], 'booking', $bookingId, ['grund' => $reason, 'erstattung' => $refund], $actor);
        $refunded = 0;
        if ($refund && $b['payment_status'] === 'paid') {
            $refunded = $this->refund($bookingId, $refundCents, 'Stornierung: ' . $reason, $actor);
        } elseif ($b['payment_status'] === 'paid') {
            // bezahlt, aber ohne Erstattung storniert: Zahlungsstatus bleibt sichtbar „bezahlt“
        }
        if ($notify) {
            $text = $refunded > 0 ? 'Der Betrag von ' . number_format($refunded / 100, 2, ',', '.') . ' € wird über PayPal erstattet.' : '';
            $this->notifyCustomer($bookingId, 'booking_cancelled', trim($reason . ' ' . $text));
        }
    }

    /** Abgelaufene Reservierungen freigeben (Cron). */
    public function expireReservations(): int
    {
        return $this->db->run(
            "UPDATE bookings SET status = 'expired', payment_status = IF(payment_status = 'pending', 'none', payment_status)
             WHERE status IN ('reserved','pending_payment') AND reserved_until < ? AND payment_status <> 'paid'",
            [$this->clock->now()->modify('-5 minutes')->format('Y-m-d H:i:s')],
        );
    }

    // ------------------------------------------------------------------
    // E-Mails
    // ------------------------------------------------------------------

    public function sendConfirmation(int $bookingId, bool $resend = false): void
    {
        $b = $this->db->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if ($b === null || $b['status'] !== 'confirmed') {
            return;
        }
        $vars = $this->mailVars($this->hydrate($b));
        $suffix = $resend ? '-' . $this->clock->now()->getTimestamp() : '';
        $this->mailer->queue('booking_confirmation', (string) $vars['teilnehmer']['email'], $vars['teilnehmer']['name'], $vars, 'booking-confirm-' . $bookingId . $suffix, 'booking', $bookingId);
        if (!$resend && ($office = $this->mailer->officeAddress()) !== null) {
            $this->mailer->queue('office_new_booking', $office, null, $vars, 'booking-office-' . $bookingId, 'booking', $bookingId);
        }
    }

    public function notifyCustomer(int $bookingId, string $template, string $message, string $keySuffix = ''): void
    {
        $b = $this->db->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if ($b === null) {
            return;
        }
        $vars = $this->mailVars($this->hydrate($b)) + ['hinweis' => $message];
        $key = $template . '-' . $bookingId . ($keySuffix !== '' ? '-' . $keySuffix : '-' . hash('crc32b', $message));
        $this->mailer->queue($template, (string) $vars['teilnehmer']['email'], $vars['teilnehmer']['name'], $vars, $key, 'booking', $bookingId);
    }

    /** Alle bestätigten Teilnehmenden eines Termins informieren (Änderung oder Absage). */
    public function notifySession(int $sessionId, string $template, string $message): int
    {
        $version = (int) $this->db->value('SELECT version FROM exam_sessions WHERE id = ?', [$sessionId]);
        $n = 0;
        foreach ($this->db->all("SELECT id FROM bookings WHERE exam_session_id = ? AND status = 'confirmed'", [$sessionId]) as $b) {
            // Termindaten der Mail aus dem aktuellen Termin, nicht aus der Buchungs-Momentaufnahme
            $this->notifyCustomerAboutSession((int) $b['id'], $sessionId, $template, $message, 'v' . $version . '-' . $template);
            $n++;
        }
        $this->audit->record('session.notified', "$n Teilnehmende informiert ($template)", 'exam_session', $sessionId);
        return $n;
    }

    /** Terminerinnerung mit den aktuellen Termindaten (idempotent je Buchung). */
    public function sendReminder(int $bookingId): void
    {
        $sid = (int) $this->db->value('SELECT exam_session_id FROM bookings WHERE id = ?', [$bookingId]);
        $this->notifyCustomerAboutSession($bookingId, $sid, 'session_reminder', '', 'reminder');
    }

    private function notifyCustomerAboutSession(int $bookingId, int $sessionId, string $template, string $message, string $key): void
    {
        $b = $this->db->one('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        $s = $this->db->one('SELECT s.*, l.name, l.street, l.address_extra, l.postal_code, l.city FROM exam_sessions s JOIN locations l ON l.id = s.location_id WHERE s.id = ?', [$sessionId]);
        if ($b === null || $s === null) {
            return;
        }
        $h = $this->hydrate($b);
        $vars = $this->mailVars($h);
        $local = (new \DateTimeImmutable((string) $s['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $vars['termin'] = [
            'datum' => $local->format('d.m.Y'), 'uhrzeit' => $local->format('H:i'),
            'ort' => trim($s['name'] . ', ' . $s['street'] . ($s['address_extra'] ? ', ' . $s['address_extra'] : '') . ', ' . $s['postal_code'] . ' ' . $s['city']),
            'hinweise' => $s['notes_public'] ?? '',
        ];
        $vars['hinweis'] = $message;
        $vars['bezahlt'] = $b['payment_status'] === 'paid';
        $this->mailer->queue($template, (string) $vars['teilnehmer']['email'], $vars['teilnehmer']['name'], $vars, $template . '-' . $bookingId . '-' . $key, 'booking', $bookingId);
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    public function mailVars(array $b): array
    {
        $s = $b['snapshot'];
        $p = $b['participant'] ?? [];
        $loc = $s['session']['location'] ?? [];
        $local = $b['starts_local'];
        return [
            'buchung' => [
                'nummer' => $b['public_ref'],
                'betrag' => number_format(((int) $b['total_cents']) / 100, 2, ',', '.') . ' ' . ($b['currency'] === 'EUR' ? '€' : $b['currency']),
                'status' => $b['status'],
            ],
            'teilnehmer' => [
                'vorname' => $p['first_name'] ?? '', 'nachname' => $p['last_name'] ?? '',
                'name' => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')), 'email' => $p['email'] ?? '',
            ],
            'pruefung' => ['name' => $s['exam']['public_name'] ?? '', 'niveau' => $s['exam']['level'] ?? '', 'anbieter' => $s['exam']['provider'] ?? ''],
            'termin' => [
                'datum' => $local ? $local->format('d.m.Y') : '',
                'uhrzeit' => $local ? $local->format('H:i') : '',
                'ort' => trim(($loc['name'] ?? '') . ', ' . ($loc['street'] ?? '') . ($loc['address_extra'] ? ', ' . $loc['address_extra'] : '') . ', ' . ($loc['postal_code'] ?? '') . ' ' . ($loc['city'] ?? ''), ', '),
                'hinweise' => $s['session']['notes_public'] ?? '',
            ],
            'preis' => [
                'positionen' => array_merge(
                    [['bezeichnung' => 'Prüfungsgebühr', 'betrag' => number_format(((int) ($s['price']['base_cents'] ?? 0)) / 100, 2, ',', '.') . ' €']],
                    array_map(fn($f) => ['bezeichnung' => $f['label'], 'betrag' => number_format($f['amount_cents'] / 100, 2, ',', '.') . ' €'], $s['price']['fees'] ?? []),
                ),
            ],
        ];
    }
}
