<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

class Request
{
    private string $method;
    private string $path;
    private string $protocol;
    /** @var array<string, string> lower-cased header names */
    private array $headers;
    private string $body;
    private array $queryParams = [];
    /** @var array<string, string> */
    private array $routeParams = [];

    public function __construct(string $method, string $path, array $headers = [], string $body = '', string $protocol = 'HTTP/1.1')
    {
        $this->method = strtoupper($method);
        $this->headers = $headers;
        $this->body = $body;
        $this->protocol = $protocol;

        $parsed = parse_url($path);
        $this->path = $parsed['path'] ?? $path;
        parse_str($parsed['query'] ?? '', $this->queryParams);
    }

    /**
     * Parses a full raw HTTP request (request line + headers + body) that
     * has already been fully received (i.e. Content-Length bytes are all
     * present). Kept for convenience/tests; HttpServer's poll loop parses
     * headers and body separately since it receives them incrementally.
     */
    public static function fromRawData(string $rawData): self
    {
        $headerEnd = strpos($rawData, "\r\n\r\n");
        if ($headerEnd === false) {
            throw new \InvalidArgumentException('Incomplete HTTP request: missing header terminator');
        }

        $headerBlob = substr($rawData, 0, $headerEnd);
        $body = substr($rawData, $headerEnd + 4);

        [$method, $path, $protocol, $headers] = self::parseHeaderBlob($headerBlob);

        return new self($method, $path, $headers, $body, $protocol);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: array<string, string>}
     */
    public static function parseHeaderBlob(string $headerBlob): array
    {
        $lines = explode("\r\n", $headerBlob);
        $requestLine = array_shift($lines) ?? '';

        $requestParts = explode(' ', $requestLine, 3);
        $method = $requestParts[0] ?? 'GET';
        $path = $requestParts[1] ?? '/';
        $protocol = $requestParts[2] ?? 'HTTP/1.1';

        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }

        return [$method, $path, $protocol, $headers];
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function json(): array
    {
        $data = json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($data) ? $data : [];
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQueryParam(string $key, $default = null)
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    public function getRouteParam(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }
}
