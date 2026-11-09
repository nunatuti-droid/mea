<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$c = \Mea\Core\Config::fromEnvironment(__DIR__);
$env = static fn(string $db) => [
    'adapter' => 'mysql',
    'host' => $c->get('DB_HOST', 'localhost'),
    'port' => (int) $c->get('DB_PORT', '3306'),
    'name' => $db,
    'user' => $c->get('DB_USER', ''),
    'pass' => $c->get('DB_PASS', '') ?? '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
        'seeds' => __DIR__ . '/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'schema_migrations',
        'default_environment' => 'main',
        'main' => $env((string) $c->get('DB_NAME', 'mea')),
        'test' => $env((string) $c->get('DB_NAME_TEST', 'mea_test')),
    ],
    'version_order' => 'creation',
];
