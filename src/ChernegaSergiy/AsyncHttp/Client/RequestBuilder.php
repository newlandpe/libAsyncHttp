<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use Generator;

/**
 * Immutable fluent request builder. Every setter returns a new instance,
 * so a builder can safely be reused as a template:
 *
 *   $base = $client->builder('POST', '/oauth/token')->header('X-App', 'survival');
 *   $tokenResponse = yield from $base->json(['grant_type' => 'refresh_token'])->send();
 */
final class RequestBuilder
{
    private HttpClient $client;
    private string $method;
    private string $url;
    private array $headers;
    private mixed $body;

    public function __construct(HttpClient $client, string $method, string $url, array $headers = [], mixed $body = null)
    {
        $this->client = $client;
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function header(string $name, string $value): self
    {
        return new self($this->client, $this->method, $this->url, [$name => $value] + $this->headers, $this->body);
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->client, $this->method, $this->url, array_merge($this->headers, $headers), $this->body);
    }

    public function json(array $data): self
    {
        return new self($this->client, $this->method, $this->url, array_merge($this->headers, ['Content-Type' => 'application/json']), $data);
    }

    public function withBody(mixed $body): self
    {
        return new self($this->client, $this->method, $this->url, $this->headers, $body);
    }

    public function method(string $method): self
    {
        return new self($this->client, $method, $this->url, $this->headers, $this->body);
    }

    public function url(string $url): self
    {
        return new self($this->client, $this->method, $url, $this->headers, $this->body);
    }

    /**
     * Executes the request as currently configured.
     */
    public function send(): Generator
    {
        return yield from $this->client->request($this->method, $this->url, $this->body, $this->headers);
    }

    public function get(): Generator
    {
        return yield from $this->method('GET')->send();
    }

    public function post(): Generator
    {
        return yield from $this->method('POST')->send();
    }

    public function put(): Generator
    {
        return yield from $this->method('PUT')->send();
    }

    public function patch(): Generator
    {
        return yield from $this->method('PATCH')->send();
    }

    public function delete(): Generator
    {
        return yield from $this->method('DELETE')->send();
    }
}
