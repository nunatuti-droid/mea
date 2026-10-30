<?php
// Hilfsprozess für den Paralleltest: reserviert einen Platz in der Testdatenbank.
// Aufruf: php reserve_worker.php <examId> <sessionId> <startzeit>
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
\Dotenv\Dotenv::createImmutable($root)->safeLoad();
$config = new \Mea\Core\Config(array_merge($_SERVER, $_ENV, [
    'DB_NAME' => getenv('MEA_TEST_DB') ?: 'mea_test', 'APP_ENV' => 'testing', 'APP_KEY' => base64_encode(str_repeat('t', 32)),
    'MAIL_DSN' => '', 'PAYPAL_CLIENT_ID' => '', 'PAYPAL_SECRET' => '',
]));
if (!str_contains((string) $config->get('DB_NAME'), 'test')) {
    exit(2);
}
$app = new \Mea\Core\App($root, $config);
// Alle Prozesse starten zur selben Zeit, damit sie wirklich gleichzeitig buchen
$start = (float) $argv[3];
while (microtime(true) < $start) {
    usleep(500);
}
try {
    $r = $app->bookings()->reserve((int) $argv[1], (int) $argv[2], [
        'first_name' => 'Parallel', 'last_name' => (string) getmypid(), 'email' => 'p' . getmypid() . '@example.org',
    ], [], []);
    echo 'ok ' . $r['ref'];
} catch (\Mea\Module\Bookings\BookingException $e) {
    echo 'abgelehnt ' . $e->getMessage();
}
