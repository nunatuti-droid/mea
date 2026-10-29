<?php
declare(strict_types=1);

// Einziger öffentlicher PHP-Einstieg. Alles andere liegt außerhalb des Webroots.
// Nur für den lokalen PHP-Entwicklungsserver: vorhandene Dateien direkt ausliefern
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new Mea\Core\App(dirname(__DIR__));
$app->handle(Mea\Core\Request::fromGlobals())->send();
