<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Nachweis der Cookie-Einwilligungen (ohne IP-Adresse): zufällige Kennung, Auswahl, Version, Zeitpunkt. */
final class CreateConsentLog extends AbstractMigration
{
    public function change(): void
    {
        $this->table('consent_log', ['signed' => false])
            ->addColumn('consent_id', 'char', ['limit' => 32])
            ->addColumn('version', 'string', ['limit' => 20])
            ->addColumn('categories_json', 'string', ['limit' => 255])
            ->addColumn('action', 'string', ['limit' => 20])
            ->addColumn('user_agent', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['consent_id'])
            ->addIndex(['created_at'])
            ->create();
    }
}
