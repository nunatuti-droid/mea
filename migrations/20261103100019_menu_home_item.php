<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Hauptmenü: Punkt „Startseite“ an erster Stelle (nur, wenn noch kein Link auf „/“ existiert). */
final class MenuHomeItem extends AbstractMigration
{
    public function up(): void
    {
        $pdo = $this->getAdapter()->getConnection();
        $menu = $pdo->query("SELECT id FROM menus WHERE menu_key = 'main'")->fetchColumn();
        if (!$menu) {
            return;
        }
        $has = $pdo->prepare("SELECT 1 FROM menu_items WHERE menu_id = ? AND url = '/'");
        $has->execute([$menu]);
        if (!$has->fetchColumn()) {
            $pdo->prepare("INSERT INTO menu_items (menu_id, parent_id, label, url, sort_order) VALUES (?, NULL, 'Startseite', '/', 0)")->execute([$menu]);
        }
    }

    public function down(): void {}
}
