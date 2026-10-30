<?php
declare(strict_types=1);

namespace Mea\Module\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * PayPal REST-API: Orders v2 (Anlegen, Einziehen), Payments v2 (Erstatten),
 * Webhook-Prüfung über /v1/notifications/verify-webhook-signature.
 * Zugangsdaten nur aus der Umgebung (PAYPAL_CLIENT_ID, PAYPAL_SECRET, PAYPAL_WEBHOOK_ID).
 */
final class PayPalGateway implements PaymentGateway
{
    private ?string $token = null;
    private int $tokenExpires = 0;

    public function __construct(
        private Client $http,
        private string $mode,
        private string $clientId,
        private string $secret,
        private string $webhookId,
    ) {}

    public static function baseUrl(string $mode): string
    {
        return $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function name(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->secret !== '' && in_array($this->mode, ['sandbox', 'live'], true);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpires - 60) {
            return $this->token;
        }
        $res = $this->call('POST', '/v1/oauth2/token', [
            'auth' => [$this->clientId, $this->secret],
            'form_params' => ['grant_type' => 'client_credentials'],
        ], false);
        $this->token = (string) ($res['access_token'] ?? '');
        $this->tokenExpires = time() + (int) ($res['expires_in'] ?? 300);
        if ($this->token === '') {
            throw new PaymentException('PayPal-Anmeldung fehlgeschlagen.');
        }
        return $this->token;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function call(string $method, string $path, array $options, bool $auth = true): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentException('PayPal ist nicht eingerichtet.');
        }
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];
        if ($auth) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->accessToken();
        }
        $options['timeout'] = 20;
        $options['connect_timeout'] = 8;
        try {
            $res = $this->http->request($method, self::baseUrl($this->mode) . $path, $options);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : '';
            $data = json_decode($body, true);
            $issue = is_array($data) ? (string) ($data['details'][0]['issue'] ?? $data['name'] ?? $data['error'] ?? '') : '';
            throw new PaymentException('PayPal-Fehler' . ($issue !== '' ? ": $issue" : '') . ' (HTTP ' . ($e->getResponse()?->getStatusCode() ?? 0) . ')', 0, $e);
        } catch (GuzzleException $e) {
            throw new PaymentException('PayPal ist nicht erreichbar.', 0, $e);
        }
        $data = json_decode((string) $res->getBody(), true);
        return is_array($data) ? $data : [];
    }

    public function createOrder(int $amountCents, string $currency, string $reference, string $description, string $returnUrl, string $cancelUrl, string $idempotencyKey): array
    {
        $amount = number_format($amountCents / 100, 2, '.', '');
        $res = $this->call('POST', '/v2/checkout/orders', [
            'headers' => ['PayPal-Request-Id' => $idempotencyKey, 'Prefer' => 'return=representation'],
            'json' => [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $reference,
                    'invoice_id' => $reference,
                    'custom_id' => $reference,
                    'description' => mb_substr($description, 0, 127),
                    'amount' => ['currency_code' => $currency, 'value' => $amount],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => 'MEA Bildungszentrum',
                    'locale' => 'de-DE',
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ]]],
            ],
        ]);
        $approve = '';
        foreach ((array) ($res['links'] ?? []) as $l) {
            if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $approve = (string) $l['href'];
            }
        }
        if (($res['id'] ?? '') === '' || !str_starts_with($approve, 'https://')) {
            throw new PaymentException('PayPal hat keinen Zahlungslink geliefert.');
        }
        return ['order_id' => (string) $res['id'], 'approve_url' => $approve];
    }

    public function captureOrder(string $orderId, string $idempotencyKey): array
    {
        try {
            $res = $this->call('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [
                'headers' => ['PayPal-Request-Id' => $idempotencyKey, 'Prefer' => 'return=representation', 'Content-Type' => 'application/json'],
                'body' => '{}',
            ]);
        } catch (PaymentException $e) {
            // Bereits eingezogen (z. B. durch Webhook oder zweiten Klick): aktuellen Stand lesen
            if (str_contains($e->getMessage(), 'ORDER_ALREADY_CAPTURED')) {
                $o = $this->getOrder($orderId);
                return $o + ['payer_email' => null];
            }
            throw $e;
        }
        return self::summarize($res) + ['payer_email' => isset($res['payer']['email_address']) ? (string) $res['payer']['email_address'] : null];
    }

    public function getOrder(string $orderId): array
    {
        return self::summarize($this->call('GET', '/v2/checkout/orders/' . rawurlencode($orderId), []));
    }

    /** @param array<string,mixed> $o @return array{status:string, capture_id:?string, amount_cents:?int, currency:?string, reference:?string} */
    private static function summarize(array $o): array
    {
        $unit = $o['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        $amount = $capture['amount'] ?? $unit['amount'] ?? null;
        return [
            'status' => (string) ($capture['status'] ?? $o['status'] ?? 'UNKNOWN'),
            'order_status' => (string) ($o['status'] ?? 'UNKNOWN'),
            'capture_id' => isset($capture['id']) ? (string) $capture['id'] : null,
            'amount_cents' => isset($amount['value']) ? (int) round(((float) $amount['value']) * 100) : null,
            'currency' => isset($amount['currency_code']) ? (string) $amount['currency_code'] : null,
            'reference' => isset($unit['custom_id']) ? (string) $unit['custom_id'] : (isset($unit['reference_id']) ? (string) $unit['reference_id'] : null),
        ];
    }

    public function refund(string $captureId, int $amountCents, string $currency, string $note, string $idempotencyKey): array
    {
        $res = $this->call('POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', [
            'headers' => ['PayPal-Request-Id' => $idempotencyKey, 'Prefer' => 'return=representation'],
            'json' => [
                'amount' => ['value' => number_format($amountCents / 100, 2, '.', ''), 'currency_code' => $currency],
                'note_to_payer' => mb_substr($note, 0, 255),
            ],
        ]);
        return ['refund_id' => (string) ($res['id'] ?? ''), 'status' => (string) ($res['status'] ?? 'UNKNOWN')];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        if ($this->webhookId === '') {
            return false;
        }
        $event = json_decode($rawBody, true);
        $need = ['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'];
        foreach ($need as $h) {
            if (($headers[$h] ?? '') === '') {
                return false;
            }
        }
        // Zertifikat nur von PayPal akzeptieren
        $host = parse_url($headers['paypal-cert-url'], PHP_URL_HOST);
        if (!is_array($event) || !is_string($host) || !preg_match('/(^|\.)paypal\.com$/', $host)) {
            return false;
        }
        try {
            $res = $this->call('POST', '/v1/notifications/verify-webhook-signature', ['json' => [
                'auth_algo' => $headers['paypal-auth-algo'],
                'cert_url' => $headers['paypal-cert-url'],
                'transmission_id' => $headers['paypal-transmission-id'],
                'transmission_sig' => $headers['paypal-transmission-sig'],
                'transmission_time' => $headers['paypal-transmission-time'],
                'webhook_id' => $this->webhookId,
                'webhook_event' => $event,
            ]]);
        } catch (PaymentException) {
            return false;
        }
        return ($res['verification_status'] ?? '') === 'SUCCESS';
    }
}
