<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Google-Bewertungen: einzelne Bewertungen aus dem Unternehmensprofil (automatisch abgerufen oder von Hand eingetragen).
 * Bei einer bestehenden Installation ohne Profil-Link wird der Link zum MEA-Profil eingetragen.
 */
final class CreateGoogleReviews extends AbstractMigration
{
    private const PROFILE_URL = 'https://www.google.com/maps?cid=7580062879415361135';

    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE google_reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  google_ref VARCHAR(255) NULL,
  source ENUM('google','manual') NOT NULL DEFAULT 'manual',
  author_name VARCHAR(120) NOT NULL,
  author_url VARCHAR(255) NULL,
  rating TINYINT UNSIGNED NOT NULL,
  body TEXT NULL,
  published_at DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_google_ref (google_ref),
  KEY ix_google_reviews_active (is_active, sort_order, published_at),
  CONSTRAINT ck_google_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
        $pdo = $this->getAdapter()->getConnection();
        if (!$pdo->query("SELECT 1 FROM settings WHERE setting_key = 'company'")->fetchColumn()) {
            return; // frische Installation: Startdaten setzen die Werte
        }
        $raw = $pdo->query("SELECT value_json FROM settings WHERE setting_key = 'google'")->fetchColumn();
        $g = $raw ? (array) json_decode((string) $raw, true) : [];
        if (trim((string) ($g['profile_url'] ?? '')) === '') {
            $g['profile_url'] = self::PROFILE_URL;
            $pdo->prepare("INSERT INTO settings (setting_key, value_json) VALUES ('google', ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)")
                ->execute([json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE google_reviews');
    }
}
