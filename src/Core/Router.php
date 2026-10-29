<?php
declare(strict_types=1);

namespace Mea\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    /** @return array{int, callable|null, array<string,string>} */
    public function match(string $method, string $path): array
    {
        $dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r): void {
            foreach ($this->routes as [$m, $p, $h]) {
                $r->addRoute($m === 'GET' ? ['GET', 'HEAD'] : $m, $p, $h);
            }
        });
        $info = $dispatcher->dispatch($method, $path);
        return match ($info[0]) {
            Dispatcher::FOUND => [200, $info[1], $info[2]],
            Dispatcher::METHOD_NOT_ALLOWED => [405, null, []],
            default => [404, null, []],
        };
    }
}
