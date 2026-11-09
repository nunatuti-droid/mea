<?php
declare(strict_types=1);

namespace Mea\Core;

/** Zugriff auf Einstellungen aus der .env-Datei. */
final class Config
{
    /** @param array<string,string> $values */
    public function __construct(private array $values) {}

    public static function fromEnvironment(string $root): self
    {
        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }
        return new self(array_merge($_SERVER, $_ENV));
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->values[$key] ?? null;
        if (!is_string($v)) {
            return $default;
        }
        // Kommentare hinter Werten entfernen (z. B. "false   # Hinweis")
        $v = trim(preg_replace('/\s+#.*$/', '', $v) ?? $v);
        return $v === '' ? $default : $v;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public function require(string $key): string
    {
        $v = $this->get($key);
        if ($v === null) {
            throw new \RuntimeException("Einstellung $key fehlt in .env");
        }
        return $v;
    }
}
