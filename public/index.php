<?php
declare(strict_types=1);

// Einziger öffentlicher PHP-Einstieg. Alles andere liegt außerhalb des Webroots.
// Nur für den lokalen PHP-Entwicklungsserver: vorhandene Dateien direkt ausliefern
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/vendor/autoload.php';

// Keine Fehlerausgabe an Besucher (Pfade, Zugangsdaten); Fehler gehen ins Server-Fehlerprotokoll
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

try {
    $app = new Mea\Core\App(dirname(__DIR__));
    $response = $app->handle(Mea\Core\Request::fromGlobals());
} catch (Throwable $e) {
    // Start der Anwendung gescheitert (z. B. Datenbank nicht erreichbar): neutrale Seite statt Fehlerdetails
    error_log('MEA Start fehlgeschlagen: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 300');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Vorübergehend nicht erreichbar</title></head><body><h1>Vorübergehend nicht erreichbar</h1><p>Bitte versuchen Sie es in wenigen Minuten erneut.</p></body></html>';
    return;
}
$response->send();
