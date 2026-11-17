<?php
declare(strict_types=1);

namespace Mea\Core;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,string> $cookies
     * @param array<string,mixed> $files hochgeladene Dateien im Format von $_FILES
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly array $files = [],
    ) {}

    /**
     * @param string|null $trustedProxies Kommagetrennte IPs/CIDR-Bereiche der vorgeschalteten Proxys (.env TRUSTED_PROXIES).
     *        Nur Anfragen von dort dürfen Besucher-IP und https über X-Forwarded-* angeben; leer = Header werden ignoriert.
     */
    public static function fromGlobals(?string $trustedProxies = null): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            $_GET,
            $_POST,
            self::applyTrustedProxy($_SERVER, $trustedProxies),
            $_COOKIE,
            $_FILES,
        );
    }

    /**
     * Hinter einem vertrauten Proxy: Besucher-IP aus X-Forwarded-For (von rechts der erste nicht vertraute Eintrag,
     * so lässt sich die Angabe nicht vom Besucher fälschen) und https aus X-Forwarded-Proto übernehmen.
     * @param array<string,mixed> $server @return array<string,mixed>
     */
    public static function applyTrustedProxy(array $server, ?string $trusted): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) $trusted)), fn($x) => $x !== ''));
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($list === [] || !self::isTrusted($remote, $list)) {
            return $server;
        }
        $chain = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($chain as $ip) {
            if (!\Mea\Module\Security\IpAddress::valid($ip)) {
                break; // unlesbare Angabe: bei der Proxy-Adresse bleiben
            }
            if (!self::isTrusted($ip, $list)) {
                $server['REMOTE_ADDR'] = $ip;
                break;
            }
        }
        if (strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https') {
            $server['HTTPS'] = 'on';
        }
        return $server;
    }

    /** @param list<string> $list */
    private static function isTrusted(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            if (\Mea\Module\Security\IpAddress::inCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    /** @return list<string> */
    public function inputList(string $key): array
    {
        $v = $this->post[$key] ?? [];
        return is_array($v) ? array_values(array_map(static fn($x) => is_string($x) ? trim($x) : '', $v)) : [];
    }

    /** @return array<string,mixed> */
    public function inputArray(string $key): array
    {
        $v = $this->post[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    public function cookie(string $key): string
    {
        $v = $this->cookies[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? '');
    }

    public function rawBody(): string
    {
        return (string) ($this->server['_RAW_BODY'] ?? file_get_contents('php://input'));
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function ip(): string
    {
        // Hinter Proxys gilt nur, was fromGlobals() über TRUSTED_PROXIES bewusst freigegeben hat; sonst ausschließlich REMOTE_ADDR.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['SERVER_PORT'] ?? '') === '443';
    }
}
