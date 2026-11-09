<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Neue Gestaltung der E-Mail-Vorlagen (Angaben als Kasten, Links als Schaltfläche).
 * Aktualisiert nur Vorlagen, die seit der Erstinstallation nicht bearbeitet wurden
 * (Vergleich mit dem Prüfwert der ursprünglichen Fassung). Eigene Texte bleiben unverändert.
 */
final class RefreshEmailTemplates extends AbstractMigration
{
    private const ORIGINAL_MD5 = [
        'booking_confirmation' => '922760424b13fba8843eb4467a05d2ca',
        'office_new_booking' => 'f40ccc07dc068422f5ed7259448bfb07',
        'booking_cancelled' => '5e3b187ae244071b1ff351b5f4dc5ebb',
        'booking_refunded' => '6de33d381255be8b7787f1dd7c926c06',
        'session_changed' => '34887941da082358570e109b37e6e04b',
        'session_cancelled' => 'b668bdfa1aacdf465d2d6f335ad021c0',
        'user_invite' => 'e5c5e83dd0b8a0b3c720a95acf644bb9',
        'password_reset' => 'a26ce1cff3bdede9c436af1b5e23a63a',
        'session_reminder' => '57ff990cae87d82e876ff3a5f2920e96',
        'inquiry_notification' => '921135f45f4eb0500996ef8631ab0bd2',
    ];

    public function up(): void
    {
        $templates = \Mea\Install\EmailTemplateSeeder::templates();
        $pdo = $this->getAdapter()->getConnection();
        $stmt = $pdo->prepare('UPDATE email_templates SET body_html = ? WHERE template_key = ? AND MD5(body_html) = ?');
        foreach (self::ORIGINAL_MD5 as $key => $md5) {
            $stmt->execute([$templates[$key]['body'], $key, $md5]);
        }
    }

    public function down(): void
    {
        // Gestaltung bleibt; ältere Fassung ist inhaltlich gleich.
    }
}
