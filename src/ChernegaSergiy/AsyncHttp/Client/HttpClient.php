<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use pocketmine\plugin\PluginBase;
use SOFe\AwaitGenerator\Await;
use Closure;
use Generator;

class HttpClient
{
    private PluginBase $plugin;
    private string $baseUrl;
    private array $defaultHeaders;
    private int $timeout;
    private int $maxRetries;

    public function __construct(PluginBase $plugin, string $baseUrl = '', array $defaultHeaders = [], int $timeout = 10, int $maxRetries = 0)
    {
        $this->plugin = $plugin;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultHeaders = $defaultHeaders;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    public function setDefaultHeaders(array $headers): self
    {
        $this->defaultHeaders = $headers;
        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    public function get(string $endpoint, array $headers = []): Generator
    {
        return yield from $this->request('GET', $endpoint, null, $headers);
    }

    public function post(string $endpoint, $data = null, array $headers = []): Generator
    {
        return yield from $this->request('POST', $endpoint, $data, $headers);
    }

    public function put(string $endpoint, $data = null, array $headers = []): Generator
    {
        return yield from $this->request('PUT', $endpoint, $data, $headers);
    }

    public function patch(string $endpoint, $data = null, array $headers = []): Generator
    {
        return yield from $this->request('PATCH', $endpoint, $data, $headers);
    }

    public function delete(string $endpoint, array $headers = []): Generator
    {
        return yield from $this->request('DELETE', $endpoint, null, $headers);
    }

    /**
     * Fluent, immutable request builder: $client->builder('POST', '/token')->json([...])->send()
     */
    public function builder(string $method, string $endpoint): RequestBuilder
    {
        return new RequestBuilder($this, $method, $endpoint);
    }

    /**
     * Performs a request. Public so RequestBuilder can delegate to it; also
     * usable directly if the get/post/put/patch/delete helpers don't fit.
     *
     * Only thread-safe scalars/arrays (method, url, headers, body, timeout)
     * are ever handed to the worker thread via HttpRequestTask. The
     * resolve/reject Closures created by Await::promise() stay on the main
     * thread the whole time (see HttpRequestTask for how).
     */
    public function request(string $method, string $endpoint, $data = null, array $headers = []): Generator
    {
        return yield from Await::promise(function (Closure $resolve, Closure $reject) use ($method, $endpoint, $data, $headers): void {
            $url = $this->baseUrl !== '' ? $this->baseUrl . '/' . ltrim($endpoint, '/') : $endpoint;

            $finalHeaders = array_merge($this->defaultHeaders, $headers);
            $body = null;

            if ($data !== null) {
                if (is_array($data) || is_object($data)) {
                    $body = json_encode($data);
                    $finalHeaders['Content-Type'] = 'application/json';
                } else {
                    $body = (string) $data;
                }
            }

            $task = new HttpRequestTask(strtoupper($method), $url, $finalHeaders, $body, $this->timeout, $this->maxRetries);
            $task->storeLocal(['resolve' => $resolve, 'reject' => $reject]);

            $this->plugin->getServer()->getAsyncPool()->submitTask($task);
        });
    }
}
