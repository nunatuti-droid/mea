<?php
declare(strict_types=1);

namespace Mea\Module\Payments;

/** Alle Zahlungsanbieter der Website. Der erste eingerichtete Anbieter ist die Vorgabe, wenn die Kundin oder der Kunde keinen wählt. */
final class GatewayRegistry
{
    private const LABELS = ['paypal' => 'PayPal', 'stripe' => 'Stripe'];

    /** @param list<PaymentGateway> $gateways */
    public function __construct(private array $gateways) {}

    public static function label(string $name): string
    {
        return self::LABELS[$name] ?? 'Zahlungsanbieter';
    }

    public function get(string $name): ?PaymentGateway
    {
        foreach ($this->gateways as $g) {
            if ($g->name() === $name) {
                return $g;
            }
        }
        return null;
    }

    /** @return list<PaymentGateway> */
    public function configured(): array
    {
        return array_values(array_filter($this->gateways, fn(PaymentGateway $g) => $g->isConfigured()));
    }

    public function default(): PaymentGateway
    {
        return $this->configured()[0] ?? $this->gateways[0];
    }

    public function isAvailable(): bool
    {
        return $this->configured() !== [];
    }

    /** @return list<PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }
}
