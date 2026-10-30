<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Prüfungskategorien (im Backend pflegbar) und Zuordnung der Prüfungen.
 * Die drei Startkategorien folgen der Einteilung auf telc.net.
 */
final class ExamCategories extends AbstractMigration
{
    public function up(): void
    {
        $this->table('exam_categories', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 120])
            ->addColumn('short_name', 'string', ['limit' => 60])
            ->addColumn('slug', 'string', ['limit' => 80])
            ->addColumn('description', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug'], ['unique' => true])
            ->create();

        $rows = [
            ['Allgemeinsprachliche Deutschprüfungen', 'Allgemeinsprachlich', 'allgemeinsprachlich',
                'Prüfungen für Alltag, Studium, Aufenthalt und Einbürgerung, von A1 bis C2.', 10],
            ['Berufsbezogene Deutschprüfungen', 'Beruf', 'beruf',
                'Prüfungen, die Deutschkenntnisse für den Arbeitsalltag nachweisen.', 20],
            ['Deutschprüfungen für Pflege und Medizin', 'Pflege und Medizin', 'pflege-medizin',
                'Prüfungen für Pflegekräfte sowie Ärztinnen und Ärzte, zum Beispiel für die Berufsanerkennung.', 30],
        ];
        $pdo = $this->getAdapter()->getConnection();
        $ins = $pdo->prepare('INSERT INTO exam_categories (name, short_name, slug, description, sort_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $ins->execute($r);
        }

        $this->table('exam_types')
            ->addColumn('category_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'category'])
            ->addForeignKey('category_id', 'exam_categories', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();
        // Bisherige Prüfungen telc Deutsch A1 bis C1 sind allgemeinsprachlich
        $this->execute("UPDATE exam_types SET category_id = (SELECT id FROM exam_categories WHERE slug = 'allgemeinsprachlich')
            WHERE slug IN ('telc-deutsch-a1','telc-deutsch-a2','telc-deutsch-a2-b1','telc-deutsch-b1','telc-deutsch-b2','telc-deutsch-c1')");
    }

    public function down(): void
    {
        $this->table('exam_types')->dropForeignKey('category_id')->removeColumn('category_id')->update();
        $this->table('exam_categories')->drop()->save();
    }
}
