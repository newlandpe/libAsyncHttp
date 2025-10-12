<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

class Request
{
    private string $method;
    private string $path;
    private array $headers;
    private string $body;
    private array $queryParams;

    public function __construct(string $method, string $path, array $headers = [], string $body = '')
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->headers = $headers;
        $this->body = $body;
        
        // Parse query string
        $parsed = parse_url($path);
        $this->path = $parsed['path'] ?? $path;
        parse_str($parsed['query'] ?? '', $this->queryParams);
    }

    public static function fromRawData(string $rawData): self
    {
        $lines = explode("\r\n", $rawData);
        $requestLine = array_shift($lines);
        
        [$method, $path] = explode(' ', $requestLine, 3);

        $headers = [];
        $body = '';
        $inBody = false;

        foreach ($lines as $line) {
            if ($line === '') {
                $inBody = true;
                continue;
            }

            if ($inBody) {
                $body .= $line . "\r\n";
            } else {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return new self($method, $path, $headers, trim($body));
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function json(): array
    {
        $data = json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : [];
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getQueryParam(string $key, $default = null)
    {
        return $this->queryParams[$key] ?? $default;
    }
}
