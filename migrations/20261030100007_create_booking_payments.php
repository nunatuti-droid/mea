<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Teilnehmerdaten, konfigurierbare Anmeldefelder, Zahlungen, Zahlungsereignisse und Erstattungen.
 * Stammdaten, Termine, Buchungen, Teilnehmende und Zahlungen sind getrennt.
 */
final class CreateBookingPayments extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE exam_form_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  exam_type_id INT UNSIGNED NOT NULL,
  field_key VARCHAR(40) NOT NULL,
  label VARCHAR(150) NOT NULL,
  field_type ENUM('text','textarea','date','select','checkbox','number') NOT NULL DEFAULT 'text',
  options_json JSON NULL,
  help_text VARCHAR(255) NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_form_field (exam_type_id, field_key),
  CONSTRAINT fk_form_field_exam FOREIGN KEY (exam_type_id) REFERENCES exam_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_participants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  salutation VARCHAR(20) NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  birth_date DATE NULL,
  birth_place VARCHAR(100) NULL,
  street VARCHAR(150) NULL,
  postal_code VARCHAR(10) NULL,
  city VARCHAR(80) NULL,
  extra_json JSON NULL,
  anonymized_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_participant_booking (booking_id),
  KEY ix_participant_email (email),
  CONSTRAINT fk_participant_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
  ADD COLUMN access_key_hash CHAR(64) NULL AFTER public_ref,
  ADD COLUMN cancelled_at DATETIME NULL AFTER confirmed_at,
  ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER cancelled_at,
  ADD COLUMN internal_note TEXT NULL AFTER cancel_reason,
  ADD COLUMN privacy_accepted_at DATETIME NULL AFTER terms_version_id;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL,
  mode ENUM('sandbox','live') NOT NULL,
  provider_order_id VARCHAR(64) NOT NULL,
  provider_capture_id VARCHAR(64) NULL,
  amount_cents INT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  status ENUM('created','approved','captured','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'created',
  refunded_cents INT UNSIGNED NOT NULL DEFAULT 0,
  payer_email_hash CHAR(64) NULL,
  last_error VARCHAR(255) NULL,
  captured_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_order (provider, provider_order_id),
  UNIQUE KEY uq_payment_capture (provider, provider_capture_id),
  KEY ix_payment_booking (booking_id),
  CONSTRAINT fk_payment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  event_id VARCHAR(100) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  resource_id VARCHAR(64) NULL,
  payload_json JSON NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  processed_at DATETIME NULL,
  result VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payment_event (provider, event_id),
  KEY ix_event_resource (resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NOT NULL,
  provider_refund_id VARCHAR(64) NULL,
  amount_cents INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_refund_provider (provider_refund_id),
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refund_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE refunds; DROP TABLE payment_events; DROP TABLE payments;
            ALTER TABLE bookings DROP COLUMN access_key_hash, DROP COLUMN cancelled_at, DROP COLUMN cancel_reason,
              DROP COLUMN internal_note, DROP COLUMN privacy_accepted_at;
            DROP TABLE booking_participants; DROP TABLE exam_form_fields;');
    }
}
