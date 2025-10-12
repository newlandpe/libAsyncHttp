<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use pocketmine\plugin\PluginBase;

class RequestBuilder
{
    private PluginBase $plugin;
    private string $method;
    private string $url;
    private array $headers = [];
    private mixed $body = null;
    private int $timeout = 10;

    public function __construct(PluginBase $plugin, string $method, string $url)
    {
        $this->plugin = $plugin;
        $this->method = strtoupper($method);
        $this->url = $url;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function setBody(mixed $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function json(array $data): self
    {
        $this->setHeader('Content-Type', 'application/json');
        $this->body = json_encode($data);
        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function send(): \Generator
    {
        // This would typically delegate to HttpClient or similar to perform the actual request
        // For now, we'll just return a dummy response or throw an exception
        throw new \RuntimeException('RequestBuilder::send() is not yet implemented to perform actual requests.');
    }
}
