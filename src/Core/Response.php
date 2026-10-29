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
    ) {}

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
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
        echo $this->body;
    }
}
