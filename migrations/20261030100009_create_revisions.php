<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Versionshistorie für Seiten, Leistungen und Prüfungsarten (ersetzt die ungenutzte page_revisions).
 * kind: published = veröffentlichter Stand, draft = Entwurf, der die Live-Seite nicht verändert.
 */
final class CreateRevisions extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
DROP TABLE page_revisions;
CREATE TABLE content_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  kind ENUM('published','draft') NOT NULL,
  snapshot_json JSON NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rev_entity (entity_type, entity_id, id),
  CONSTRAINT fk_rev_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE exam_type_media ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE media ADD COLUMN kind ENUM('image','document') NOT NULL DEFAULT 'image' AFTER mime,
  ADD COLUMN public_path VARCHAR(255) NULL AFTER storage_path;
SQL);
    }

    public function down(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE media DROP COLUMN kind, DROP COLUMN public_path;
ALTER TABLE exam_type_media DROP COLUMN created_at;
DROP TABLE content_revisions;
CREATE TABLE page_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id INT UNSIGNED NOT NULL,
  snapshot_json JSON NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rev_page (page_id),
  CONSTRAINT fk_rev_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }
}
