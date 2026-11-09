<?php
declare(strict_types=1);

namespace Mea\Module\Content;

use Mea\Core\Database;

/** Firmendaten und Texte, die im Backend gepflegt werden (Schlüssel/Wert als JSON). */
final class SettingsRepository
{
    /** @var array<string,mixed>|null */
    private ?array $all = null;

    public function __construct(private Database $db) {}

    /** @return array<string,mixed> */
    public function all(): array
    {
        if ($this->all === null) {
            $this->all = [];
            foreach ($this->db->all('SELECT setting_key, value_json FROM settings') as $r) {
                $this->all[$r['setting_key']] = json_decode((string) $r['value_json'], true);
            }
        }
        return $this->all;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->db->run(
            'INSERT INTO settings (setting_key, value_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
        $this->all = null;
    }
}
