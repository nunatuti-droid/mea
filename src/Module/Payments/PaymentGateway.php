<?php
declare(strict_types=1);

namespace Mea\Module\Payments;

/**
 * Austauschbare Zahlungsschnittstelle. Heute PayPal; Stripe oder andere Anbieter
 * implementieren dieselbe Schnittstelle. Kartendaten berühren nie diesen Server.
 */
interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /** sandbox oder live */
    public function mode(): string;

    /**
     * Zahlungsvorgang serverseitig anlegen.
     * @return array{order_id:string, approve_url:string}
     */
    public function createOrder(int $amountCents, string $currency, string $reference, string $description, string $returnUrl, string $cancelUrl, string $idempotencyKey): array;

    /**
     * Zahlung einziehen (nach Freigabe durch die Kundin oder den Kunden).
     * @return array{status:string, capture_id:?string, amount_cents:?int, currency:?string, payer_email:?string}
     */
    public function captureOrder(string $orderId, string $idempotencyKey): array;

    /** @return array{status:string, capture_id:?string, amount_cents:?int, currency:?string, reference:?string} */
    public function getOrder(string $orderId): array;

    /** @return array{refund_id:string, status:string} */
    public function refund(string $captureId, int $amountCents, string $currency, string $note, string $idempotencyKey): array;

    /** Echtheit einer Webhook-Benachrichtigung beim Anbieter prüfen. @param array<string,string> $headers */
    public function verifyWebhook(array $headers, string $rawBody): bool;
}
