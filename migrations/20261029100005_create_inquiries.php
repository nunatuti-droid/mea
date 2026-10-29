<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Kontaktanfragen und einfache Rate-Limits (IP nur als Hash gespeichert). */
final class CreateInquiries extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE inquiries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  topic VARCHAR(80) NULL,
  location_id INT UNSIGNED NULL,
  message TEXT NOT NULL,
  privacy_accepted_at DATETIME NOT NULL,
  status ENUM('new','in_progress','done','spam') NOT NULL DEFAULT 'new',
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_inquiry_status (status, created_at),
  CONSTRAINT fk_inquiry_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  bucket VARCHAR(80) NOT NULL,
  subject_hash CHAR(64) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  PRIMARY KEY (bucket, subject_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE rate_limits; DROP TABLE inquiries;');
    }
}
