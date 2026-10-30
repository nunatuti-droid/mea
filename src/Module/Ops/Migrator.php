<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Phinx\Config\Config as PhinxConfig;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Führt Datenbankmigrationen aus der Anwendung heraus aus (Update, Wiederherstellung). */
final class Migrator
{
    public function __construct(private \PDO $pdo, private string $migrationsDir) {}

    private function manager(BufferedOutput $out): Manager
    {
        $name = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $config = new PhinxConfig([
            'paths' => ['migrations' => $this->migrationsDir],
            'environments' => [
                'default_migration_table' => 'schema_migrations',
                'default_environment' => 'app',
                'app' => ['name' => $name, 'connection' => $this->pdo, 'adapter' => 'mysql'],
            ],
            'version_order' => 'creation',
        ]);
        return new Manager($config, new ArrayInput([]), $out);
    }

    /** @return string Ausgabe für das Protokoll */
    public function migrate(): string
    {
        $out = new BufferedOutput();
        $this->manager($out)->migrate('app');
        return $out->fetch();
    }

    /** Anzahl noch nicht ausgeführter Migrationen. */
    public function pending(): int
    {
        $done = array_map('strval', $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        $n = 0;
        foreach (glob($this->migrationsDir . '/*.php') ?: [] as $f) {
            if (preg_match('/^(\d{14})_/', basename($f), $m) && !in_array($m[1], $done, true)) {
                $n++;
            }
        }
        return $n;
    }

    public function currentVersion(): string
    {
        return (string) ($this->pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() ?: '0');
    }
}
