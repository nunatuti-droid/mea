<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Kurztitel für die AVGS-Unterleistungen, damit Kartentitel einzeilig bleiben. Nur wenn noch kein Kurztitel gesetzt ist. */
final class ShortServiceTitles extends AbstractMigration
{
    public function up(): void
    {
        foreach (['mea-plus' => 'MEA Plus', 'stabilisierung' => 'Job-Mentoring', 'ganzheitliche-betreuung' => 'Betreuung nach § 16k SGB II'] as $slug => $t) {
            $this->getAdapter()->getConnection()
                ->prepare("UPDATE services SET menu_title = ? WHERE slug = ? AND (menu_title IS NULL OR menu_title = '')")
                ->execute([$t, $slug]);
        }
    }

    public function down(): void {}
}
