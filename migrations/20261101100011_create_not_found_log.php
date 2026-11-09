<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 6 (Umzug): Protokoll nicht gefundener Adressen (ohne IP), damit nach dem Umzug
 * fehlende Weiterleitungen sichtbar werden. Ergänzt Weiterleitungen für alte Theme-Adressen.
 */
final class CreateNotFoundLog extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE not_found_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  referrer_host VARCHAR(120) NULL,
  first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  UNIQUE KEY uq_not_found_path (path),
  KEY idx_not_found_open (resolved_at, hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO redirects (source_path, target_url, status_code) VALUES
  ('/edublink_megamenu/*', NULL, 410),
  ('/edublink_footer/*', NULL, 410),
  ('/author/*', NULL, 410),
  ('/course-category/*', NULL, 410),
  ('/course-tag/*', NULL, 410),
  ('/product-category/*', '/telc-pruefungszentrum/', 301),
  ('/product/*', '/telc-pruefungszentrum/', 301),
  ('/courses/*', '/telc-pruefungszentrum/', 301),
  ('/feed/', NULL, 410),
  ('/comments/feed/', NULL, 410),
  ('/wp-json/*', NULL, 410);
SQL);
    }

    public function down(): void
    {
        $this->execute("DROP TABLE not_found_log;
DELETE FROM redirects WHERE source_path IN ('/edublink_megamenu/*','/edublink_footer/*','/author/*','/course-category/*','/course-tag/*',
  '/product-category/*','/product/*','/courses/*','/feed/','/comments/feed/','/wp-json/*');");
    }
}
