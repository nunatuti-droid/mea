<?php
declare(strict_types=1);

namespace Mea\Module\Ops;

use Mea\Core\Config;
use Mea\Module\Content\SettingsRepository;

/**
 * Website online/offline und Suchmaschinen-Indexierung, im Backend schaltbar.
 * Ohne gespeicherte Einstellung gilt: online, Indexierung laut APP_NOINDEX.
 */
final class SiteStatus
{
    public const KEY = 'site_status';

    public function __construct(private SettingsRepository $settings, private Config $config) {}

    public function isOnline(): bool
    {
        return (bool) ($this->value()['online'] ?? true);
    }

    public function indexingAllowed(): bool
    {
        $v = $this->value();
        return array_key_exists('indexing', $v) ? (bool) $v['indexing'] : !$this->config->bool('APP_NOINDEX');
    }

    public function offlineMessage(): string
    {
        $m = trim((string) ($this->value()['message'] ?? ''));
        return $m !== '' ? $m : 'Wir überarbeiten gerade unsere Website und sind in Kürze wieder für Sie da. Telefonisch erreichen Sie uns wie gewohnt.';
    }

    /** @param array{online?:bool, indexing?:bool, message?:string} $changes */
    public function save(array $changes): void
    {
        $this->settings->set(self::KEY, array_merge($this->value(), $changes));
    }

    /** @return array<string,mixed> */
    private function value(): array
    {
        $v = $this->settings->get(self::KEY, []);
        return is_array($v) ? $v : [];
    }

    /** Pfade, die auch offline erreichbar bleiben: Verwaltung, Zahlungsbenachrichtigungen, Dateien. */
    public static function alwaysReachable(string $path): bool
    {
        return str_starts_with($path, '/verwaltung/')
            || str_starts_with($path, '/zahlung/')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/media/')
            || $path === '/robots.txt';
    }
}
