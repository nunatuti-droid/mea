<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** E-Mail-Vorlagen und Versand-Warteschlange (zugleich Versandprotokoll). */
final class CreateEmail extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE email_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  subject VARCHAR(200) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  placeholders_json JSON NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_template_key (template_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_outbox (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idempotency_key VARCHAR(120) NOT NULL,
  template_key VARCHAR(60) NULL,
  to_email VARCHAR(190) NOT NULL,
  to_name VARCHAR(150) NULL,
  subject VARCHAR(200) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  next_attempt_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  sent_at DATETIME NULL,
  related_type VARCHAR(40) NULL,
  related_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_outbox_key (idempotency_key),
  KEY ix_outbox_due (status, next_attempt_at),
  KEY ix_outbox_related (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE email_outbox; DROP TABLE email_templates;');
    }
}
