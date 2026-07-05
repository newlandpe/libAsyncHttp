<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

class Router
{
    /** @var array<string, CompiledRoute[]> method => list of compiled routes, checked in registration order */
    private array $routes = [];

    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function any(string $path, callable $handler): self
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $path, $handler);
    }

    /**
     * @param string|string[] $methods
     */
    private function addRoute(string|array $methods, string $path, callable $handler): self
    {
        $compiled = new CompiledRoute($path, $handler);

        foreach (is_array($methods) ? $methods : [$methods] as $method) {
            $this->routes[strtoupper($method)][] = $compiled;
        }

        return $this;
    }

    public function handle(Request $request, Response $response): void
    {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }

            $request->setRouteParams($params);
            ($route->getHandler())($request, $response);
            return;
        }

        $response->setStatus(404);
        $response->json(['error' => 'Not Found']);
    }

    private function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
