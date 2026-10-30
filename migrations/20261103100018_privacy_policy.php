<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Datenschutzerklärung passend zu den Funktionen der Website (Google Maps, PayPal, Cookie-Einwilligung usw.).
 * Ersetzt nur den ursprünglichen Platzhaltertext; eine in der Verwaltung bearbeitete Fassung bleibt unverändert.
 */
final class PrivacyPolicy extends AbstractMigration
{
    public function up(): void
    {
        $this->getAdapter()->getConnection()
            ->prepare("UPDATE pages SET body_html = ?, updated_at = NOW() WHERE slug = 'datenschutz' AND body_html = ?")
            ->execute([\Mea\Install\PrivacyPolicy::html(), \Mea\Install\PrivacyPolicy::SEEDED_HTML]);
    }

    public function down(): void {}
}
