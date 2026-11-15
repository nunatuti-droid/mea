<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Stripe-Kennungen (Checkout-Sitzung cs_… hat rund 66 Zeichen) passten nicht in die 64-stelligen Felder von PayPal.
 * Die Felder werden auf 255 Zeichen erweitert, damit Zahlung und Rückkehr zusammenfinden.
 */
final class WidenPaymentIds extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE payments
  MODIFY provider_order_id VARCHAR(255) NOT NULL,
  MODIFY provider_capture_id VARCHAR(255) NULL;
ALTER TABLE payment_events MODIFY resource_id VARCHAR(255) NULL;
ALTER TABLE refunds MODIFY provider_refund_id VARCHAR(255) NULL;
SQL);
    }

    public function down(): void
    {
        // Verkürzen würde Kennungen abschneiden; die größeren Felder schaden nicht.
    }
}
