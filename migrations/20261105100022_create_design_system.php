<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Designsystem: gespeicherte Abweichungen von den Standardwerten (die Standardwerte stehen im Code,
 * Mea\Module\Design\DesignSchema) und selbst hochgeladene Schriften.
 */
final class CreateDesignSystem extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE design_tokens (
  token_key VARCHAR(64) NOT NULL PRIMARY KEY,
  value_json VARCHAR(255) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_design_tokens_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
        $this->execute(<<<'SQL'
CREATE TABLE design_fonts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  family VARCHAR(40) NOT NULL,
  file VARCHAR(80) NOT NULL,
  weight_min SMALLINT UNSIGNED NOT NULL DEFAULT 400,
  weight_max SMALLINT UNSIGNED NOT NULL DEFAULT 400,
  style ENUM('normal','italic') NOT NULL DEFAULT 'normal',
  fallback ENUM('sans','serif') NOT NULL DEFAULT 'sans',
  original_name VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_design_font (family, style),
  CONSTRAINT ck_design_font_weight CHECK (weight_min BETWEEN 100 AND 900 AND weight_max BETWEEN 100 AND 900 AND weight_min <= weight_max)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE design_fonts');
        $this->execute('DROP TABLE design_tokens');
        $this->execute("DELETE FROM settings WHERE setting_key = 'design_cache'");
    }
}
