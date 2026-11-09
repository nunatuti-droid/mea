<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Betrieb: Anwendungsschutz (Ereignisse, IP-Regeln), Sicherungen, Updates,
 * Läufe der Wartungsjobs und des Dateiwächters.
 */
final class CreateOperations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE security_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(40) NOT NULL,
  severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  points TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash CHAR(64) NULL,
  ip_masked VARCHAR(45) NULL,
  method VARCHAR(10) NULL,
  path VARCHAR(255) NULL,
  detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sec_time (created_at),
  KEY ix_sec_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ip_rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule ENUM('block','allow') NOT NULL,
  cidr VARCHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  ip_masked VARCHAR(45) NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  source ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  expires_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ip_hash (ip_hash),
  KEY ix_ip_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(120) NOT NULL,
  kind ENUM('manual','nightly','pre_restore','pre_update','uploaded') NOT NULL,
  status ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  size_bytes BIGINT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  app_version VARCHAR(30) NULL,
  schema_version VARCHAR(20) NULL,
  note VARCHAR(255) NULL,
  error VARCHAR(255) NULL,
  verified_at DATETIME NULL,
  downloaded_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  UNIQUE KEY uq_backup_file (file_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_updates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(30) NOT NULL,
  from_version VARCHAR(30) NOT NULL,
  status ENUM('running','installed','failed','rolled_back') NOT NULL DEFAULT 'running',
  package_sha256 CHAR(64) NOT NULL,
  changelog TEXT NULL,
  backup_id INT UNSIGNED NULL,
  log TEXT NULL,
  installed_by INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY ix_update_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cron_runs (
  job VARCHAR(40) NOT NULL PRIMARY KEY,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_status ENUM('ok','failed','running') NULL,
  last_message VARCHAR(255) NULL,
  duration_ms INT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE integrity_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status ENUM('ok','changed','no_reference','invalid_reference') NOT NULL,
  reference_version VARCHAR(30) NULL,
  checked_files INT UNSIGNED NOT NULL DEFAULT 0,
  changed_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE booking_participants ADD reminder_sent_at DATETIME NULL;
SQL);
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE booking_participants DROP COLUMN reminder_sent_at;
            DROP TABLE integrity_runs; DROP TABLE cron_runs; DROP TABLE app_updates; DROP TABLE backups; DROP TABLE ip_rules; DROP TABLE security_events;');
    }
}
