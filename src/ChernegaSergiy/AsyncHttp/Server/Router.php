<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

class Router
{
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

    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    public function any(string $path, callable $handler): self
    {
        return $this->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $path, $handler);
    }

    private function addRoute($methods, string $path, callable $handler): self
    {
        if (!is_array($methods)) {
            $methods = [$methods];
        }

        foreach ($methods as $method) {
            $this->routes[$method][$this->normalizePath($path)] = $handler;
        }

        return $this;
    }

    public function handle(Request $request, Response $response): void
    {
        $method = $request->getMethod();
        $path = $this->normalizePath($request->getPath());

        if (!isset($this->routes[$method][$path])) {
            $response->setStatus(404);
            $response->json(['error' => 'Not Found']);
            return;
        }

        $handler = $this->routes[$method][$path];
        $handler($request, $response);
    }

    private function normalizePath(string $path): string
    {
        return '/' . trim($path, '/');
    }
}
