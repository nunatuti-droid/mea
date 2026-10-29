<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dynamische Prüfungsverwaltung: Prüfungsarten sind Datensätze, keine Codeteile.
 * Neue Prüfungen brauchen weder neue Tabellen noch neue Templates.
 */
final class CreateExams extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE exam_providers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  website VARCHAR(255) NULL,
  logo_media_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_provider_logo FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  registration_days_before SMALLINT UNSIGNED NOT NULL DEFAULT 14,
  reservation_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  free_cancellation_days SMALLINT UNSIGNED NULL,
  cancellation_fee_cents INT UNSIGNED NULL,
  cancellation_fee_percent DECIMAL(5,2) NULL,
  refund_allowed TINYINT(1) NOT NULL DEFAULT 1,
  required_fields_json JSON NULL,
  terms_html TEXT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_id INT UNSIGNED NULL,
  slug VARCHAR(120) NOT NULL,
  internal_name VARCHAR(150) NOT NULL,
  public_name VARCHAR(150) NOT NULL,
  level_label VARCHAR(60) NULL,
  category VARCHAR(80) NULL,
  exam_format VARCHAR(120) NULL,
  short_text VARCHAR(400) NOT NULL,
  body_html MEDIUMTEXT NULL,
  target_group_html TEXT NULL,
  requirements_html TEXT NULL,
  procedure_html TEXT NULL,
  duration_text VARCHAR(120) NULL,
  base_price_cents INT UNSIGNED NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  booking_rule_id INT UNSIGNED NULL,
  card_media_id INT UNSIGNED NULL,
  hero_media_id INT UNSIGNED NULL,
  seo_title VARCHAR(70) NULL,
  seo_description VARCHAR(170) NULL,
  status ENUM('draft','published','disabled','archived') NOT NULL DEFAULT 'draft',
  is_listed TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exam_slug (slug),
  KEY ix_exam_status (status, is_listed, sort_order),
  CONSTRAINT fk_exam_provider FOREIGN KEY (provider_id) REFERENCES exam_providers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_exam_rule FOREIGN KEY (booking_rule_id) REFERENCES booking_rules(id) ON DELETE RESTRICT,
  CONSTRAINT fk_exam_card FOREIGN KEY (card_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_exam_hero FOREIGN KEY (hero_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_type_fees (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_type_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  amount_cents INT UNSIGNED NOT NULL,
  is_mandatory TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_fee_exam FOREIGN KEY (exam_type_id) REFERENCES exam_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_type_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_type_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NOT NULL,
  role ENUM('gallery','document') NOT NULL DEFAULT 'gallery',
  label VARCHAR(150) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_etm_exam FOREIGN KEY (exam_type_id) REFERENCES exam_types(id) ON DELETE CASCADE,
  CONSTRAINT fk_etm_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_type_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  starts_at DATETIME NOT NULL COMMENT 'UTC',
  ends_at DATETIME NULL COMMENT 'UTC',
  registration_until DATETIME NOT NULL COMMENT 'UTC',
  capacity SMALLINT UNSIGNED NOT NULL,
  price_cents INT UNSIGNED NULL COMMENT 'NULL = Grundpreis der Prüfungsart',
  booking_rule_id INT UNSIGNED NULL,
  status ENUM('open','closed','cancelled','done') NOT NULL DEFAULT 'open',
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  notes_public TEXT NULL,
  notes_internal TEXT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_session_exam (exam_type_id, starts_at),
  KEY ix_session_status (status, starts_at),
  CONSTRAINT fk_session_exam FOREIGN KEY (exam_type_id) REFERENCES exam_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_session_rule FOREIGN KEY (booking_rule_id) REFERENCES booking_rules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE terms_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  sha256 CHAR(64) NOT NULL,
  valid_from DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_ref CHAR(12) NOT NULL,
  exam_session_id INT UNSIGNED NOT NULL,
  exam_type_id INT UNSIGNED NOT NULL,
  snapshot_json JSON NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  terms_version_id INT UNSIGNED NULL,
  status ENUM('reserved','pending_payment','confirmed','cancelled','expired','refunded') NOT NULL,
  payment_status ENUM('none','pending','paid','failed','refunded','partially_refunded') NOT NULL DEFAULT 'none',
  reserved_until DATETIME NULL,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_booking_ref (public_ref),
  KEY ix_booking_session (exam_session_id, status),
  KEY ix_booking_expiry (status, reserved_until),
  CONSTRAINT fk_booking_session FOREIGN KEY (exam_session_id) REFERENCES exam_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_exam FOREIGN KEY (exam_type_id) REFERENCES exam_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_booking_terms FOREIGN KEY (terms_version_id) REFERENCES terms_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE bookings; DROP TABLE terms_versions; DROP TABLE exam_sessions;
            DROP TABLE exam_type_media; DROP TABLE exam_type_fees; DROP TABLE exam_types;
            DROP TABLE booking_rules; DROP TABLE exam_providers;');
    }
}
