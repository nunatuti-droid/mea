<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Alle telc-Prüfungen finden nur im zertifizierten Prüfungszentrum Herne statt.
 * - Neues Merkmal „Prüfungszentrum“ je Standort (Herne = ja).
 * - Künftige Termine an anderen Standorten werden nach Herne verlegt, sofern keine echten Buchungen bestehen.
 * - SEO-Titel „in Marl & Herne“ werden angepasst, aber nur, wenn sie noch dem Originaltext entsprechen.
 */
final class ExamCenterHerne extends AbstractMigration
{
    public function up(): void
    {
        $this->table('locations')
            ->addColumn('is_exam_center', 'boolean', ['default' => false, 'null' => false, 'after' => 'is_headquarters'])
            ->update();
        $this->execute("UPDATE locations SET is_exam_center = 1 WHERE slug = 'herne'");

        $this->execute(
            "UPDATE exam_sessions s
             JOIN locations l ON l.id = s.location_id AND l.is_exam_center = 0
             JOIN (SELECT id FROM locations WHERE slug = 'herne' LIMIT 1) h
             SET s.location_id = h.id
             WHERE s.starts_at > UTC_TIMESTAMP()
               AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.exam_session_id = s.id AND b.public_ref NOT LIKE 'DEMO%')"
        );

        $this->execute("UPDATE exam_types SET seo_title = REPLACE(seo_title, ' in Marl & Herne', ' in Herne') WHERE seo_title LIKE 'telc Deutsch % Prüfung in Marl & Herne'");
        $this->execute("UPDATE pages SET seo_title = 'telc-Prüfungen in Herne – Termine und Anmeldung' WHERE seo_title = 'telc-Prüfungen in Marl & Herne – Termine und Anmeldung'");
        $this->execute("UPDATE pages SET seo_description = 'Übersicht der Prüfungsgebühren für telc-Sprachprüfungen im zertifizierten telc-Prüfungszentrum des MEA Bildungszentrums in Herne.' WHERE seo_description = 'Übersicht der Prüfungsgebühren für telc-Sprachprüfungen im MEA Bildungszentrum in Marl und Herne.'");
    }

    public function down(): void
    {
        $this->table('locations')->removeColumn('is_exam_center')->update();
    }
}
