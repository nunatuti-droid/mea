<?php
declare(strict_types=1);

namespace Mea\Module\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Stripe Checkout (gehostete Zahlungsseite). Die Kundin oder der Kunde zahlt bei Stripe; Kartendaten berühren diesen Server nie.
 * „Bestellung“ = Checkout-Sitzung, „Einzug“ = serverseitiges Lesen des Zahlungsstatus, „capture_id“ = Charge (ch_…), die für Erstattungen dient.
 * Zugangsdaten nur aus der Umgebung (STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET). Testschlüssel (sk_test_…) = Sandbox, Live-Schlüssel (sk_live_…) = live.
 * Zahlarten stellt man im Stripe-Dashboard ein (Karte, SEPA-Lastschrift, Apple/Google Pay …).
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com';
    private const TOLERANCE = 300;

    public function __construct(
        private Client $http,
        private string $secretKey,
        private string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return (bool) preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{8,}$/', $this->secretKey);
    }

    public function mode(): string
    {
        return str_contains($this->secretKey, '_live_') ? 'live' : 'sandbox';
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function call(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentException('Stripe ist nicht eingerichtet.');
        }
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        $options['auth'] = [$this->secretKey, ''];
        $options['timeout'] = 20;
        $options['connect_timeout'] = 8;
        try {
            $res = $this->http->request($method, self::API . $path, $options);
        } catch (RequestException $e) {
            $data = json_decode($e->hasResponse() ? (string) $e->getResponse()?->getBody() : '', true);
            $code = is_array($data) ? (string) ($data['error']['code'] ?? $data['error']['type'] ?? '') : '';
            throw new PaymentException('Stripe-Fehler' . ($code !== '' ? ": $code" : '') . ' (HTTP ' . ($e->getResponse()?->getStatusCode() ?? 0) . ')', 0, $e);
        } catch (GuzzleException $e) {
            throw new PaymentException('Stripe ist nicht erreichbar.', 0, $e);
        }
        $data = json_decode((string) $res->getBody(), true);
        return is_array($data) ? $data : [];
    }

    public function createOrder(int $amountCents, string $currency, string $reference, string $description, string $returnUrl, string $cancelUrl, string $idempotencyKey): array
    {
        $res = $this->call('POST', '/v1/checkout/sessions', [
            'headers' => ['Idempotency-Key' => mb_substr($idempotencyKey, 0, 250)],
            'form_params' => [
                'mode' => 'payment',
                'locale' => 'de',
                'client_reference_id' => $reference,
                'success_url' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => ['currency' => strtolower($currency), 'unit_amount' => $amountCents, 'product_data' => ['name' => mb_substr($description, 0, 250)]],
                ]],
                'metadata' => ['ref' => $reference],
                'payment_intent_data' => ['metadata' => ['ref' => $reference], 'description' => mb_substr($description, 0, 250)],
            ],
        ]);
        $url = (string) ($res['url'] ?? '');
        if (($res['id'] ?? '') === '' || !str_starts_with($url, 'https://')) {
            throw new PaymentException('Stripe hat keinen Zahlungslink geliefert.');
        }
        return ['order_id' => (string) $res['id'], 'approve_url' => $url];
    }

    public function captureOrder(string $orderId, string $idempotencyKey): array
    {
        // Bei Checkout zieht Stripe selbst ein; hier wird nur der Stand serverseitig gelesen und geprüft.
        return $this->fetch($orderId) + ['payer_email' => null];
    }

    public function getOrder(string $orderId): array
    {
        return $this->fetch($orderId);
    }

    /** @return array{status:string, order_status:string, capture_id:?string, amount_cents:?int, currency:?string, reference:?string, payer_email:?string} */
    private function fetch(string $sessionId): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            throw new PaymentException('Ungültige Stripe-Sitzung.');
        }
        $s = $this->call('GET', '/v1/checkout/sessions/' . $sessionId, ['query' => ['expand' => ['payment_intent.latest_charge']]]);
        $pi = is_array($s['payment_intent'] ?? null) ? $s['payment_intent'] : [];
        $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge'] : [];
        $paid = ($s['payment_status'] ?? '') === 'paid';
        $status = match (true) {
            $paid => 'COMPLETED',
            ($s['status'] ?? '') === 'expired' => 'EXPIRED',
            ($pi['status'] ?? '') === 'requires_payment_method' && ($s['status'] ?? '') === 'complete' => 'DECLINED',
            default => 'PENDING', // offen oder SEPA-Lastschrift in Bearbeitung
        };
        return [
            'status' => $status,
            'order_status' => (string) ($s['status'] ?? 'unknown'),
            'capture_id' => isset($charge['id']) && $paid ? (string) $charge['id'] : null,
            'amount_cents' => isset($s['amount_total']) ? (int) $s['amount_total'] : null,
            'currency' => isset($s['currency']) ? strtoupper((string) $s['currency']) : null,
            'reference' => isset($s['client_reference_id']) ? (string) $s['client_reference_id'] : null,
            'payer_email' => isset($s['customer_details']['email']) ? (string) $s['customer_details']['email'] : null,
        ];
    }

    public function refund(string $captureId, int $amountCents, string $currency, string $note, string $idempotencyKey): array
    {
        $res = $this->call('POST', '/v1/refunds', [
            'headers' => ['Idempotency-Key' => mb_substr($idempotencyKey, 0, 250)],
            'form_params' => ['charge' => $captureId, 'amount' => $amountCents, 'reason' => 'requested_by_customer', 'metadata' => ['note' => mb_substr($note, 0, 400)]],
        ]);
        $status = (string) ($res['status'] ?? 'unknown');
        return ['refund_id' => (string) ($res['id'] ?? ''), 'status' => match ($status) { 'succeeded' => 'COMPLETED', 'pending' => 'PENDING', default => strtoupper($status) }];
    }

    /** Stripe-Signatur prüfen: HMAC-SHA256 über „Zeitstempel.Rohtext“, höchstens 5 Minuten alt. */
    public function verifyWebhook(array $headers, string $rawBody, ?int $now = null): bool
    {
        $sig = (string) ($headers['stripe-signature'] ?? '');
        if ($this->webhookSecret === '' || $sig === '') {
            return false;
        }
        $t = null;
        $v1 = [];
        foreach (explode(',', $sig) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = ctype_digit($v) ? (int) $v : null;
            } elseif ($k === 'v1' && $v !== '') {
                $v1[] = $v;
            }
        }
        if ($t === null || $v1 === [] || abs(($now ?? time()) - $t) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $this->webhookSecret);
        foreach ($v1 as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }
}
