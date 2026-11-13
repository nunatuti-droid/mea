<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * „Gerät merken“ (2FA-Abfrage für 30 Tage überspringen) und „Angemeldet bleiben“ (längere Sitzung).
 * Vom vertrauenswürdigen Gerät wird nur der Hash des Cookie-Tokens gespeichert; jede Zeile ist einzeln widerrufbar.
 */
final class TrustedDevicesAndRememberLogin extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE user_trusted_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_trusted_token (token_hash),
  KEY ix_trusted_user (user_id, revoked_at),
  CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_sessions ADD COLUMN remember TINYINT(1) NOT NULL DEFAULT 0 AFTER user_agent;
SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE user_sessions DROP COLUMN remember; DROP TABLE user_trusted_devices;');
    }
}
