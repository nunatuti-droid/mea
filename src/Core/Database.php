<?php
declare(strict_types=1);

namespace Mea\Core;

use PDO;

/** Dünne PDO-Schicht: nur Prepared Statements, Fehler als Exceptions. */
final class Database
{
    private PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
    }

    /** Vorhandene Verbindung verwenden (Migrationen). */
    public static function wrap(PDO $pdo): self
    {
        $db = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->pdo = $pdo;
        return $db;
    }

    public static function fromConfig(Config $c, ?string $name = null): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $c->get('DB_HOST', 'localhost'),
            $c->get('DB_PORT', '3306'),
            $name ?? $c->require('DB_NAME'),
        );
        return new self($dsn, $c->require('DB_USER'), $c->get('DB_PASS', '') ?? '');
    }

    /** @param array<string|int,mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @param array<string|int,mixed> $params @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string|int,mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException('Ungültiger Tabellenname');
        }
        $cols = array_keys($row);
        foreach ($cols as $c) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $c)) {
                throw new \InvalidArgumentException('Ungültiger Spaltenname');
            }
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn($c) => ':' . $c, $cols)),
        );
        $this->run($sql, $row);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Läuft bereits eine Transaktion, wird $fn darin ausgeführt (kein Verschachteln).
     * @template T @param callable(self):T $fn @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
