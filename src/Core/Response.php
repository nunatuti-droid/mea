<?php
declare(strict_types=1);

namespace Mea\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        /** @var list<string> fertige Set-Cookie-Werte */
        public array $cookies = [],
        /** Datei, die beim Senden gestreamt wird (große Downloads) */
        public ?string $file = null,
    ) {}

    /** Datei als Download (wird beim Senden in Blöcken gelesen). */
    public static function download(string $path, string $name, string $type = 'application/octet-stream'): self
    {
        return new self('', 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"',
            'Content-Length' => (string) (filesize($path) ?: 0),
        ], [], $path);
    }

    /** @param array{expires?:int,secure?:bool,samesite?:string,path?:string,httponly?:bool} $o */
    public function withCookie(string $name, string $value, array $o = []): self
    {
        $c = clone $this;
        $parts = [rawurlencode($name) . '=' . rawurlencode($value), 'Path=' . ($o['path'] ?? '/')];
        if (isset($o['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', $o['expires']) . ' GMT';
            $parts[] = 'Max-Age=' . max(0, $o['expires'] - time());
        }
        if ($o['secure'] ?? false) {
            $parts[] = 'Secure';
        }
        if ($o['httponly'] ?? true) {
            $parts[] = 'HttpOnly';
        }
        $parts[] = 'SameSite=' . ($o['samesite'] ?? 'Lax');
        $c->cookies[] = implode('; ', $parts);
        return $c;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json']);
    }

    public static function text(string $body, string $type = 'text/plain'): self
    {
        return new self($body, 200, ['Content-Type' => $type . '; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $c = clone $this;
        $c->headers[$name] = $value;
        return $c;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if ($this->file !== null) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            readfile($this->file);
            return;
        }
        echo $this->body;
    }
}
