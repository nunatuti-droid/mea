<?php
declare(strict_types=1);

namespace Mea\Tests\Integration;

use Mea\Core\App;
use Mea\Core\Config;
use Mea\Core\Database;
use Mea\Core\Request;
use Mea\Core\Response;
use Mea\Install\ContentSeeder;
use Mea\Module\Media\ImageVariantGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Baut die Testdatenbank (DB_NAME_TEST) einmal pro Lauf neu auf: Migrationen + Startdaten.
 * Jeder Test läuft in einer Transaktion, die danach zurückgerollt wird.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Database $db = null;
    private static bool $dirty = false;
    /** false für Tests mit DDL (Wiederherstellung, Update): danach wird die Testdatenbank neu aufgebaut */
    protected bool $transactional = true;
    private static ?Config $config = null;
    protected App $app;

    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function db(): Database
    {
        if (self::$db === null) {
            $env = Config::fromEnvironment(self::root());
            $name = $env->get('DB_NAME_TEST', 'mea_test') ?? 'mea_test';
            if (!str_contains($name, 'test')) {
                throw new \RuntimeException('Die Testdatenbank muss "test" im Namen tragen.');
            }
            $values = array_merge($_SERVER, $_ENV, [
                'DB_NAME' => $name, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
                'APP_NOINDEX' => 'true', 'APP_URL' => 'https://mea.test', 'APP_KEY' => base64_encode(str_repeat('t', 32)), 'MAIL_DSN' => '', 'PAYPAL_CLIENT_ID' => '', 'PAYPAL_SECRET' => '',
            ]);
            self::$config = new Config($values);
            $db = Database::fromConfig(self::$config);
            $db->run('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($db->all('SHOW TABLES') as $row) {
                $db->run('DROP TABLE `' . str_replace('`', '', (string) reset($row)) . '`');
            }
            $db->run('SET FOREIGN_KEY_CHECKS = 1');
            $cmd = escapeshellarg(self::root() . '/vendor/bin/phinx') . ' migrate -q -e test --configuration='
                . escapeshellarg(self::root() . '/phinx.php');
            exec($cmd . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException("Migration fehlgeschlagen:\n" . implode("\n", $out));
            }
            $tmp = sys_get_temp_dir() . '/mea-test-media-' . getmypid();
            $gen = new ImageVariantGenerator($db, $tmp . '/uploads', $tmp . '/public');
            (new ContentSeeder($db, $gen, self::root() . '/seeds/media'))->run();
            self::$db = $db;
        }
        return self::$db;
    }

    protected function setUp(): void
    {
        if (self::$dirty) {
            self::$db = null;
            self::$dirty = false;
        }
        $db = self::db();
        if ($this->transactional) {
            $db->pdo()->beginTransaction();
        }
        $this->app = new App(self::root(), self::$config, $db);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (self::db()->pdo()->inTransaction()) {
            self::db()->pdo()->rollBack();
        }
        if (!$this->transactional) {
            self::$dirty = true;
        }
    }

    protected static function config(): Config
    {
        self::db();
        return self::$config;
    }

    /** @param array<string,mixed> $post */
    protected function request(string $method, string $uri, array $post = [], array $server = []): Response
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        return $this->app->handle(new Request($method, $path, $query, $post, $server + ['REMOTE_ADDR' => '203.0.113.7']));
    }

    protected function get(string $uri): Response
    {
        return $this->request('GET', $uri);
    }

    /** Legt eine neue Prüfungsart rein per Daten an – genau wie später das Backend. */
    protected function createExam(string $slug, string $status = 'published', ?int $price = 12345): int
    {
        return self::db()->insert('exam_types', [
            'slug' => $slug, 'internal_name' => 'Test ' . $slug, 'public_name' => 'Testprüfung ' . strtoupper($slug),
            'level_label' => 'Z9', 'short_text' => 'Kurzbeschreibung für ' . $slug,
            'body_html' => '<p>Beschreibung ' . $slug . '</p>', 'base_price_cents' => $price,
            'status' => $status, 'is_listed' => 1, 'sort_order' => 999,
        ]);
    }

    protected function createSession(int $examId, int $capacity = 10, string $startsIn = '+30 days', string $deadlineIn = '+16 days', ?int $price = null): int
    {
        $loc = (int) self::db()->value('SELECT id FROM locations ORDER BY id LIMIT 1');
        $utc = new \DateTimeZone('UTC');
        return self::db()->insert('exam_sessions', [
            'exam_type_id' => $examId, 'location_id' => $loc,
            'starts_at' => (new \DateTimeImmutable($startsIn, $utc))->format('Y-m-d H:i:s'),
            'registration_until' => (new \DateTimeImmutable($deadlineIn, $utc))->format('Y-m-d H:i:s'),
            'capacity' => $capacity, 'price_cents' => $price, 'status' => 'open',
        ]);
    }

    protected function book(int $examId, int $sessionId, string $status, ?string $reservedUntil = null): void
    {
        self::db()->insert('bookings', [
            'public_ref' => strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)), 'exam_session_id' => $sessionId,
            'exam_type_id' => $examId, 'snapshot_json' => '{}', 'total_cents' => 100, 'currency' => 'EUR',
            'status' => $status, 'reserved_until' => $reservedUntil,
        ]);
    }

    /** Benutzer mit Rolle anlegen und als angemeldet markieren (ohne Formular). @return array<string,mixed> */
    protected function loginAs(string $role = 'admin', string $email = ''): array
    {
        $email = $email !== '' ? $email : $role . '-' . bin2hex(random_bytes(3)) . '@example.org';
        $id = self::db()->insert('users', [
            'email' => $email, 'name' => 'Test ' . ucfirst($role), 'role' => $role, 'status' => 'active',
            'password_hash' => \Mea\Module\Auth\PasswordPolicy::hash('Lange sichere Passphrase 2026'),
            'totp_secret_enc' => $this->app->crypto->encrypt('JBSWY3DPEHPK3PXP', 'totp'),
            'totp_enabled_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $user = self::db()->one('SELECT * FROM users WHERE id = ?', [$id]);
        $s = $this->app->auth->startSession($user, '203.0.113.7', 'PHPUnit', '');
        $_SESSION['admin_token'] = $s['token'];
        return $user;
    }

    /** POST in der Verwaltung mit gültigem CSRF-Token. @param array<string,mixed> $data */
    protected function adminPost(string $uri, array $data): Response
    {
        return $this->request('POST', $uri, $data + ['_csrf' => \Mea\Core\Csrf::token()]);
    }

    protected function idFromLocation(Response $r): int
    {
        $loc = (string) ($r->headers['Location'] ?? '');
        self::assertMatchesRegularExpression('#/(\d+)/(\?.*)?$#', $loc, 'Weiterleitung auf Datensatz erwartet, erhalten: ' . $loc . ' ' . substr((string) $r->body, 0, 300));
        preg_match('#/(\d+)/(\?.*)?$#', $loc, $m);
        return (int) $m[1];
    }
}
