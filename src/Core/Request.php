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

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES,
        );
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
        // Hinter Proxys wird nur REMOTE_ADDR vertraut; ein Proxy muss bewusst konfiguriert werden.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['SERVER_PORT'] ?? '') === '443';
    }
}
