<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Allgemeinsprachliche telc-Deutschprüfungen A1 bis C2 (Lizenz des Prüfungszentrums Herne):
 * - bestehende Prüfungen A1 bis C1 und A2·B1 bekommen ausführliche, sachliche Texte nach telc.net,
 *   aber nur, wenn die Kurzbeschreibung noch dem ursprünglichen Text entspricht (eigene Texte bleiben);
 * - C1 Hochschule und C2 werden als Entwurf angelegt (ohne Preis; Veröffentlichen in der Verwaltung).
 */
final class TelcGermanExams extends AbstractMigration
{
    private const ORIGINAL_SHORT = [
        'telc-deutsch-a1' => 'Für Einsteigerinnen und Einsteiger, die vertraute Alltagsausdrücke und einfache Sätze verstehen und verwenden können.',
        'telc-deutsch-a2' => 'Für alle, die sich in einfachen, routinemäßigen Situationen des Alltags verständigen können.',
        'telc-deutsch-a2-b1' => 'Eine Prüfung, zwei mögliche Niveaus: Je nach Ergebnis erhalten Sie ein Zertifikat auf A2 oder B1.',
        'telc-deutsch-b1' => 'Der häufig verlangte Nachweis für Ausbildung, Beruf, Aufenthaltstitel und Einbürgerung.',
        'telc-deutsch-b2' => 'Für Beruf und Studium: selbstständige Sprachverwendung auch bei komplexeren Themen.',
        'telc-deutsch-c1' => 'Für anspruchsvolle berufliche und akademische Ziele.',
    ];
    private const TEXT_FIELDS = ['public_name', 'internal_name', 'exam_format', 'short_text', 'body_html', 'target_group_html', 'procedure_html', 'duration_text'];

    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $exams = \Mea\Install\TelcGermanExams::all();
        foreach (self::ORIGINAL_SHORT as $slug => $orig) {
            $e = $exams[$slug];
            $set = implode(', ', array_map(fn($f) => "$f = ?", self::TEXT_FIELDS));
            $pdo->prepare("UPDATE exam_types SET $set WHERE slug = ? AND short_text = ?")
                ->execute([...array_map(fn($f) => $e[$f], self::TEXT_FIELDS), $slug, $orig]);
        }
        $general = $pdo->query("SELECT id FROM exam_categories WHERE slug = 'allgemeinsprachlich'")->fetchColumn() ?: null;
        $tpl = $pdo->query("SELECT provider_id, booking_rule_id, card_media_id, hero_media_id, requirements_html FROM exam_types WHERE slug = 'telc-deutsch-c1'")->fetch(\PDO::FETCH_ASSOC);
        if (!$tpl) {
            return; // Neuinstallation: die Startdaten legen alle Prüfungen selbst an.
        }
        foreach (['telc-deutsch-c1-hochschule', 'telc-deutsch-c2'] as $slug) {
            $exists = $pdo->prepare('SELECT 1 FROM exam_types WHERE slug = ?');
            $exists->execute([$slug]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $e = $exams[$slug];
            $row = [
                'slug' => $slug, 'level_label' => $e['level_label'], 'sort_order' => $e['sort_order'], 'category' => 'Deutsch', 'category_id' => $general,
                'currency' => 'EUR', 'status' => 'draft', 'is_listed' => 1,
                'seo_title' => mb_substr($e['public_name'] . ' Prüfung in Herne', 0, 70),
                'seo_description' => mb_substr($e['public_name'] . ' beim MEA Bildungszentrum im telc-Prüfungszentrum Herne: Ablauf, Termine, Preise und Online-Anmeldung.', 0, 170),
            ] + array_intersect_key($e, array_flip(self::TEXT_FIELDS)) + $tpl;
            $cols = array_keys($row);
            $pdo->prepare('INSERT INTO exam_types (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($row));
        }
    }

    public function down(): void {}
}
