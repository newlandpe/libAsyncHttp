<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetRequestResult;
use SOFe\AwaitGenerator\Await;
use Closure;
use Throwable;

class HttpClient
{
    private PluginBase $plugin;
    private string $baseUrl;
    private array $defaultHeaders;
    private int $timeout;

    public function __construct(PluginBase $plugin, string $baseUrl = '', array $defaultHeaders = [], int $timeout = 10)
    {
        $this->plugin = $plugin;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultHeaders = $defaultHeaders;
        $this->timeout = $timeout;
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

    public function get(string $endpoint, array $headers = []): \Generator
    {
        return yield from $this->request('GET', $endpoint, null, $headers);
    }

    public function post(string $endpoint, $data = null, array $headers = []): \Generator
    {
        return yield from $this->request('POST', $endpoint, $data, $headers);
    }

    public function put(string $endpoint, $data = null, array $headers = []): \Generator
    {
        return yield from $this->request('PUT', $endpoint, $data, $headers);
    }

    public function delete(string $endpoint, array $headers = []): \Generator
    {
        return yield from $this->request('DELETE', $endpoint, null, $headers);
    }

    private function request(string $method, string $endpoint, $data = null, array $headers = []): \Generator
    {
        return yield from Await::promise(function (Closure $resolve, Closure $reject) use ($method, $endpoint, $data, $headers) {
            $url = $this->baseUrl ? $this->baseUrl . '/' . ltrim($endpoint, '/') : $endpoint;
            
            $finalHeaders = array_merge($this->defaultHeaders, $headers);
            $body = null;

            if ($data !== null) {
                if (is_array($data) || is_object($data)) {
                    $body = json_encode($data);
                    $finalHeaders['Content-Type'] = 'application/json';
                } else {
                    $body = (string)$data;
                }
            }

            $this->plugin->getServer()->getAsyncPool()->submitTask(
                new class($method, $url, $finalHeaders, $body, $this->timeout, $resolve, $reject) extends \pocketmine\scheduler\AsyncTask {
                    private string $method;
                    private string $url;
                    private array $headers;
                    private ?string $body;
                    private int $timeout;
                    private Closure $resolve;
                    private Closure $reject;

                    public function __construct(string $method, string $url, array $headers, ?string $body, int $timeout, Closure $resolve, Closure $reject)
                    {
                        $this->method = $method;
                        $this->url = $url;
                        $this->headers = $headers;
                        $this->body = $body;
                        $this->timeout = $timeout;
                        $this->resolve = $resolve;
                        $this->reject = $reject;
                    }

                    public function onRun(): void
                    {
                        try {
                            $result = Internet::simpleCurl($this->url, $this->timeout, $this->headers, [
                                CURLOPT_CUSTOMREQUEST => $this->method,
                                CURLOPT_POSTFIELDS => $this->body
                            ]);
                            $this->setResult(['success' => true, 'result' => $result]);
                        } catch (Throwable $e) {
                            $this->setResult(['success' => false, 'error' => $e->getMessage()]);
                        }
                    }

                    public function onCompletion(): void
                    {
                        $result = $this->getResult();
                        if ($result['success']) {
                            ($this->resolve)(new Response($result['result']));
                        } else {
                            ($this->reject)(new \Exception($result['error']));
                        }
                    }
                }
            );
        });
    }
}
