<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Mediathek: Originale liegen in storage/uploads, Varianten in public/media. */
final class CreateMedia extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  original_name VARCHAR(255) NOT NULL,
  storage_path VARCHAR(255) NOT NULL,
  mime VARCHAR(60) NOT NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL,
  alt_text VARCHAR(300) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL DEFAULT '',
  credit VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_media_sha (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_variants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id INT UNSIGNED NOT NULL,
  width INT UNSIGNED NOT NULL,
  height INT UNSIGNED NOT NULL,
  format VARCHAR(10) NOT NULL,
  path VARCHAR(255) NOT NULL,
  CONSTRAINT fk_variant_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
  KEY ix_variant_media (media_id, width)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_usages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id INT UNSIGNED NOT NULL,
  owner_type VARCHAR(40) NOT NULL,
  owner_id INT UNSIGNED NOT NULL,
  role VARCHAR(40) NOT NULL DEFAULT 'image',
  CONSTRAINT fk_usage_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_usage (media_id, owner_type, owner_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE media_usages; DROP TABLE media_variants; DROP TABLE media;');
    }
}
