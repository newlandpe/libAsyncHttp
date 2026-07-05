<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

/**
 * A route path compiled to a regex, e.g. "/users/{id}" becomes able to
 * match "/users/15" and extract ['id' => '15'].
 */
final class CompiledRoute
{
    private string $regex;
    /** @var string[] */
    private array $paramNames;
    /** @var callable */
    private $handler;

    public function __construct(string $path, callable $handler)
    {
        $this->handler = $handler;
        [$this->regex, $this->paramNames] = self::compile($path);
    }

    /**
     * @return array{0: string, 1: string[]}
     */
    private static function compile(string $path): array
    {
        $paramNames = [];
        $parts = [];

        $trimmed = trim($path, '/');
        foreach ($trimmed === '' ? [] : explode('/', $trimmed) as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $m) === 1) {
                $paramNames[] = $m[1];
                $parts[] = '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        $regex = '#^/' . implode('/', $parts) . '$#';
        return [$regex, $paramNames];
    }

    /**
     * @return array<string, string>|null null if the path does not match
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $matches[$name];
        }

        return $params;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }
}
