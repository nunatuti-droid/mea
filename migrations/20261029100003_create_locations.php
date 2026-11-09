<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Standorte als Datensätze; beliebig viele, mit Öffnungszeiten und Feiertagen. */
final class CreateLocations extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  short_name VARCHAR(60) NOT NULL,
  is_headquarters TINYINT(1) NOT NULL DEFAULT 0,
  street VARCHAR(150) NOT NULL,
  address_extra VARCHAR(150) NULL,
  postal_code VARCHAR(10) NOT NULL,
  city VARCHAR(80) NOT NULL,
  phone VARCHAR(40) NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  directions_html TEXT NULL,
  image_media_id INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_loc_media FOREIGN KEY (image_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE location_hours (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id INT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  opens_at TIME NOT NULL,
  closes_at TIME NOT NULL,
  CONSTRAINT fk_hours_loc FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT ck_weekday CHECK (weekday BETWEEN 1 AND 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL,
  name VARCHAR(100) NOT NULL,
  region VARCHAR(10) NOT NULL DEFAULT 'NW',
  UNIQUE KEY uq_holiday (holiday_date, region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE holidays; DROP TABLE location_hours; DROP TABLE locations;');
    }
}
