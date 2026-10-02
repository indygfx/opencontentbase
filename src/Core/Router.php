<?php

declare(strict_types=1);

namespace Core;

final class Router
{
    /** @var list<string> path segments that never match a placeholder (e.g. /pages/new vs /pages/{slug}) */
    private const RESERVED = ['new', 'preview'];

    /** @var list<array{pattern: string, handler: callable, roles: list<string>}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler, array $roles = []): void
    {
        $this->add('GET', $pattern, $handler, $roles);
    }

    public function post(string $pattern, callable $handler, array $roles = []): void
    {
        $this->add('POST', $pattern, $handler, $roles);
    }

    private function add(string $method, string $pattern, callable $handler, array $roles): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'roles' => $roles,
        ];
    }

    /**
     * @return array{handler: callable, params: array<string, string>, roles: list<string>}
     */
    public function dispatch(string $method, string $path): array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $params = $this->match($route['pattern'], $path);
            if ($params !== null) {
                return ['handler' => $route['handler'], 'params' => $params, 'roles' => $route['roles']];
            }
        }
        throw new RouteNotFoundException("Keine Route fuer {$method} {$path}");
    }

    /** @return array<string, string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $patternParts = $pattern === '/' ? [''] : explode('/', trim($pattern, '/'));
        $pathParts = $path === '/' ? [''] : explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];
        foreach ($patternParts as $i => $part) {
            if (preg_match('/^\{(\w+)\}$/', $part, $m) === 1) {
                if ($pathParts[$i] === '' || in_array($pathParts[$i], self::RESERVED, true)) {
                    return null;
                }
                $params[$m[1]] = rawurldecode($pathParts[$i]);
                continue;
            }
            if ($part !== $pathParts[$i]) {
                return null;
            }
        }
        return $params;
    }
}
