<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bewerbungsformular: Tabellen für Bewerbungen und Unterlagen (Dateien verschlüsselt in storage/applications),
 * E-Mail-Vorlage „Neue Bewerbung“, Link auf der Karriereseite und passender Abschnitt in der Datenschutzerklärung.
 * Texte werden nur ersetzt, wenn sie noch der ursprünglichen Fassung entsprechen.
 */
final class CreateApplications extends AbstractMigration
{
    private const CAREER_OLD = 'Schicken Sie uns Ihre Unterlagen über das <a href="/kontakt/?thema=sonstiges">Kontaktformular</a> oder per E-Mail.';
    private const CAREER_NEW = 'Bewerben Sie sich direkt über unser <a href="/karriere/bewerbung/">Bewerbungsformular</a>. Dort laden Sie Ihre Unterlagen als PDF hoch.';

    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE applications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  position VARCHAR(40) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  message TEXT NULL,
  status ENUM('new','in_progress','closed') NOT NULL DEFAULT 'new',
  privacy_accepted_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_application_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE application_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  stored_name CHAR(64) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_application_file (stored_name),
  CONSTRAINT fk_application_file FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
        $pdo = $this->getAdapter()->getConnection();
        if ($pdo->query("SHOW TABLES LIKE 'email_templates'")->fetchColumn()) {
            $t = \Mea\Install\EmailTemplateSeeder::templates()['application_notification'];
            $pdo->prepare('INSERT IGNORE INTO email_templates (template_key, name, description, subject, body_html, placeholders_json) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(['application_notification', $t['name'], $t['description'], $t['subject'], $t['body'], json_encode($t['vars'], JSON_UNESCAPED_UNICODE)]);
        }
        $pdo->prepare("UPDATE pages SET body_html = REPLACE(body_html, ?, ?) WHERE slug = 'karriere'")->execute([self::CAREER_OLD, self::CAREER_NEW]);
        $pdo->prepare("UPDATE pages SET body_html = REPLACE(body_html, ?, ?) WHERE slug = 'datenschutz'")
            ->execute([\Mea\Install\PrivacyPolicy::APPLICATIONS_HTML_V1, \Mea\Install\PrivacyPolicy::APPLICATIONS_HTML]);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE application_files; DROP TABLE applications;');
    }
}
