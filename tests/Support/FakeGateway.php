<?php
declare(strict_types=1);

namespace Mea\Tests\Support;

use Mea\Module\Payments\PaymentGateway;

/** Testdouble für PayPal: merkt sich Aufrufe und liefert vorgegebene Antworten. */
final class FakeGateway implements PaymentGateway
{
    /** @var array<string, array{amount:int, currency:string, reference:string, return:string, cancel:string}> */
    public array $orders = [];
    /** @var list<array{capture:string, amount:int}> */
    public array $refunds = [];
    public bool $webhookValid = true;
    public int $captureCalls = 0;
    /** Betrag, den PayPal beim Einzug meldet (null = Bestellbetrag) */
    public ?int $capturedAmount = null;

    public function name(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    public function createOrder(int $amountCents, string $currency, string $reference, string $description, string $returnUrl, string $cancelUrl, string $idempotencyKey): array
    {
        $id = 'ORDER' . strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12));
        $this->orders[$id] = ['amount' => $amountCents, 'currency' => $currency, 'reference' => $reference, 'return' => $returnUrl, 'cancel' => $cancelUrl];
        return ['order_id' => $id, 'approve_url' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . $id];
    }

    public function captureOrder(string $orderId, string $idempotencyKey): array
    {
        $this->captureCalls++;
        $o = $this->orders[$orderId];
        return ['status' => 'COMPLETED', 'capture_id' => 'CAP' . substr($orderId, 5), 'amount_cents' => $this->capturedAmount ?? $o['amount'],
            'currency' => $o['currency'], 'payer_email' => 'payer@example.org'];
    }

    public function getOrder(string $orderId): array
    {
        $o = $this->orders[$orderId] ?? null;
        return ['status' => $o ? 'APPROVED' : 'UNKNOWN', 'capture_id' => null, 'amount_cents' => $o['amount'] ?? null, 'currency' => $o['currency'] ?? null, 'reference' => $o['reference'] ?? null];
    }

    public function refund(string $captureId, int $amountCents, string $currency, string $note, string $idempotencyKey): array
    {
        $this->refunds[] = ['capture' => $captureId, 'amount' => $amountCents];
        return ['refund_id' => 'REF' . count($this->refunds), 'status' => 'COMPLETED'];
    }

    public function verifyWebhook(array $headers, string $rawBody): bool
    {
        return $this->webhookValid;
    }

    /** Rückleitungs-URL nach Freigabe bei PayPal, so wie der Browser sie aufruft. */
    public function returnUrlFor(string $orderId): string
    {
        $u = $this->orders[$orderId]['return'];
        return (string) preg_replace('#^https?://[^/]+#', '', $u) . '&token=' . $orderId . '&PayerID=TESTPAYER';
    }
}
