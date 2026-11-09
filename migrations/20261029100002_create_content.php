<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Seiten, Leistungen, Menüs, FAQ, Bewertungen, Einstellungen, Weiterleitungen. */
final class CreateContent extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  value_json JSON NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  intro VARCHAR(500) NULL,
  body_html MEDIUMTEXT NULL,
  hero_media_id INT UNSIGNED NULL,
  template ENUM('default','legal','about') NOT NULL DEFAULT 'default',
  seo_title VARCHAR(70) NULL,
  seo_description VARCHAR(170) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pages_slug (slug),
  CONSTRAINT fk_pages_media FOREIGN KEY (hero_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id INT UNSIGNED NOT NULL,
  snapshot_json JSON NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rev_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
  KEY ix_rev_page (page_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  menu_title VARCHAR(80) NULL,
  icon VARCHAR(40) NULL,
  teaser VARCHAR(400) NOT NULL,
  intro VARCHAR(600) NULL,
  body_html MEDIUMTEXT NULL,
  facts_json JSON NULL,
  hero_media_id INT UNSIGNED NULL,
  card_media_id INT UNSIGNED NULL,
  cta_label VARCHAR(80) NULL,
  cta_url VARCHAR(255) NULL,
  download_media_label VARCHAR(120) NULL,
  download_url VARCHAR(255) NULL,
  seo_title VARCHAR(70) NULL,
  seo_description VARCHAR(170) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_services_slug (parent_id, slug),
  CONSTRAINT fk_services_parent FOREIGN KEY (parent_id) REFERENCES services(id) ON DELETE RESTRICT,
  CONSTRAINT fk_services_hero FOREIGN KEY (hero_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_services_card FOREIGN KEY (card_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menus (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_key VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  label VARCHAR(80) NOT NULL,
  url VARCHAR(255) NOT NULL,
  description VARCHAR(160) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_mi_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  CONSTRAINT fk_mi_parent FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  KEY ix_mi (menu_id, parent_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(300) NOT NULL,
  answer_html TEXT NOT NULL,
  category VARCHAR(80) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faq_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  faq_id INT UNSIGNED NOT NULL,
  target_type ENUM('home','faq_page','exam_type','service','page') NOT NULL,
  target_id INT UNSIGNED NULL,
  CONSTRAINT fk_fa_faq FOREIGN KEY (faq_id) REFERENCES faqs(id) ON DELETE CASCADE,
  KEY ix_fa_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(80) NOT NULL,
  role_label VARCHAR(120) NULL,
  body TEXT NOT NULL,
  rating TINYINT UNSIGNED NULL,
  source_note VARCHAR(200) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  show_in_hero TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT ck_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redirects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_path VARCHAR(255) NOT NULL,
  target_url VARCHAR(255) NULL,
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_redirect_source (source_path),
  CONSTRAINT ck_redirect_code CHECK (status_code IN (301, 302, 410))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE redirects; DROP TABLE reviews; DROP TABLE faq_assignments; DROP TABLE faqs;
            DROP TABLE menu_items; DROP TABLE menus; DROP TABLE services; DROP TABLE page_revisions;
            DROP TABLE pages; DROP TABLE settings;');
    }
}
