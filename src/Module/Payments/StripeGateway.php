<?php
declare(strict_types=1);

namespace Mea\Module\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Stripe über Checkout (gehostete Zahlungsseite): Kartendaten berühren nie diesen Server.
 * Ein „Order“ ist hier eine Checkout-Session. Zahlung wird erst nach serverseitigem Abgleich gebucht.
 * Zugangsdaten nur aus der Umgebung (STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET); Testmodus bei Schlüssel sk_test_….
 */
final class StripeGateway implements PaymentGateway
{
    private const BASE = 'https://api.stripe.com';
    /** Zeitfenster für Webhook-Signaturen (Sekunden) */
    private const TOLERANCE = 300;

    public function __construct(private Client $http, private string $secretKey, private string $webhookSecret) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $this->secretKey) === 1;
    }

    public function mode(): string
    {
        return str_starts_with($this->secretKey, 'sk_live_') ? 'live' : 'sandbox';
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function call(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentException('Stripe ist nicht eingerichtet.');
        }
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->secretKey];
        $options['timeout'] = 20;
        $options['connect_timeout'] = 8;
        try {
            $res = $this->http->request($method, self::BASE . $path, $options);
        } catch (RequestException $e) {
            $data = json_decode((string) $e->getResponse()?->getBody(), true);
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
            'headers' => ['Idempotency-Key' => $idempotencyKey],
            'form_params' => [
                'mode' => 'payment',
                'client_reference_id' => $reference,
                'locale' => 'de',
                'success_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'unit_amount' => $amountCents,
                        'product_data' => ['name' => mb_substr($description, 0, 250)],
                    ],
                ]],
                'payment_intent_data' => ['metadata' => ['reference' => $reference]],
                'metadata' => ['reference' => $reference],
            ],
        ]);
        $url = (string) ($res['url'] ?? '');
        if (($res['id'] ?? '') === '' || !str_starts_with($url, 'https://')) {
            throw new PaymentException('Stripe hat keinen Zahlungslink geliefert.');
        }
        return ['order_id' => (string) $res['id'], 'approve_url' => $url];
    }

    /** Bei Stripe wird beim Bezahlen sofort eingezogen: hier nur den Stand abfragen. */
    public function captureOrder(string $orderId, string $idempotencyKey): array
    {
        return $this->getOrder($orderId) + ['payer_email' => null];
    }

    public function getOrder(string $orderId): array
    {
        $o = $this->call('GET', '/v1/checkout/sessions/' . rawurlencode($orderId));
        $paid = ($o['payment_status'] ?? '') === 'paid';
        $pi = $o['payment_intent'] ?? null;
        return [
            // PayPal-Begriffe, damit BookingService beide gleich behandeln kann
            'status' => $paid ? 'COMPLETED' : (($o['status'] ?? '') === 'expired' ? 'FAILED' : 'PENDING'),
            'order_status' => $paid ? 'COMPLETED' : strtoupper((string) ($o['status'] ?? 'UNKNOWN')),
            'capture_id' => is_string($pi) && $pi !== '' ? $pi : null,
            'amount_cents' => isset($o['amount_total']) ? (int) $o['amount_total'] : null,
            'currency' => isset($o['currency']) ? strtoupper((string) $o['currency']) : null,
            'reference' => isset($o['client_reference_id']) ? (string) $o['client_reference_id'] : null,
        ];
    }

    /** $captureId ist hier die PaymentIntent-ID. */
    public function refund(string $captureId, int $amountCents, string $currency, string $note, string $idempotencyKey): array
    {
        $res = $this->call('POST', '/v1/refunds', [
            'headers' => ['Idempotency-Key' => $idempotencyKey],
            'form_params' => ['payment_intent' => $captureId, 'amount' => $amountCents, 'metadata' => ['note' => mb_substr($note, 0, 400)]],
        ]);
        return ['refund_id' => (string) ($res['id'] ?? ''), 'status' => strtoupper((string) ($res['status'] ?? 'UNKNOWN'))];
    }

    /** Stripe-Signatur: HMAC-SHA256 über „Zeitstempel.Rohtext“ mit dem Webhook-Geheimnis (Kopfzeile Stripe-Signature). */
    public function verifyWebhook(array $headers, string $rawBody, ?int $now = null): bool
    {
        if ($this->webhookSecret === '') {
            return false;
        }
        $sig = (string) ($headers['stripe-signature'] ?? '');
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
