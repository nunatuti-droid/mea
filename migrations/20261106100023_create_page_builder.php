<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Seitenbaukasten: Seitenaufbau als geprüfter Baum (veröffentlicht und Entwurf), Startseite als eigene Seite,
 * globale Bausteine. Nur Tabellen: Die Übernahme der bisherigen Seiten (Mea\Module\Builder\LegacyImport) läuft beim ersten
 * Aufruf mit dem neuen Programmstand (App::pages()), weil ein Update die Migration im noch laufenden alten Prozess ausführt.
 */
final class CreatePageBuilder extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE pages
  ADD COLUMN is_home TINYINT(1) NULL DEFAULT NULL AFTER is_system,
  ADD COLUMN blocks_json LONGTEXT NULL AFTER body_html,
  ADD COLUMN draft_json LONGTEXT NULL AFTER blocks_json,
  ADD COLUMN draft_rev INT UNSIGNED NOT NULL DEFAULT 0 AFTER draft_json,
  ADD COLUMN draft_by INT UNSIGNED NULL AFTER draft_rev,
  ADD COLUMN draft_updated_at DATETIME NULL AFTER draft_by,
  ADD COLUMN css_hash VARCHAR(16) NULL AFTER draft_updated_at,
  ADD UNIQUE KEY uq_pages_home (is_home),
  ADD CONSTRAINT ck_pages_home CHECK (is_home IS NULL OR is_home = 1),
  ADD CONSTRAINT ck_pages_blocks CHECK (blocks_json IS NULL OR JSON_VALID(blocks_json)),
  ADD CONSTRAINT ck_pages_draft CHECK (draft_json IS NULL OR JSON_VALID(draft_json)),
  ADD CONSTRAINT fk_pages_draft_by FOREIGN KEY (draft_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE builder_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  tree_json LONGTEXT NOT NULL,
  draft_json LONGTEXT NULL,
  draft_rev INT UNSIGNED NOT NULL DEFAULT 0,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT ck_bc_tree CHECK (JSON_VALID(tree_json)),
  CONSTRAINT ck_bc_draft CHECK (draft_json IS NULL OR JSON_VALID(draft_json)),
  CONSTRAINT fk_bc_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_bc_updated FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users MODIFY role ENUM('admin','editor','booking','author') NOT NULL;
SQL);
    }

    public function down(): void
    {
        $this->execute("UPDATE users SET role = 'editor' WHERE role = 'author';
            ALTER TABLE users MODIFY role ENUM('admin','editor','booking') NOT NULL;
            DELETE FROM pages WHERE is_home = 1 AND slug = 'startseite';
            DROP TABLE builder_components;
            ALTER TABLE pages DROP FOREIGN KEY fk_pages_draft_by, DROP CONSTRAINT ck_pages_home, DROP CONSTRAINT ck_pages_blocks, DROP CONSTRAINT ck_pages_draft,
              DROP INDEX uq_pages_home, DROP COLUMN is_home, DROP COLUMN blocks_json, DROP COLUMN draft_json, DROP COLUMN draft_rev,
              DROP COLUMN draft_by, DROP COLUMN draft_updated_at, DROP COLUMN css_hash;");
    }
}
