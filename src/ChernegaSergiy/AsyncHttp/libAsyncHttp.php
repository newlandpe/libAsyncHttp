<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp;

use ChernegaSergiy\AsyncHttp\Client\HttpClient;
use ChernegaSergiy\AsyncHttp\Server\HttpServer;
use pocketmine\plugin\PluginBase;

class libAsyncHttp
{
    private static ?self $instance = null;
    private PluginBase $plugin;
    private ?HttpServer $server = null;
    private HttpClient $client;

    public function __construct(PluginBase $plugin)
    {
        self::$instance = $this;
        $this->plugin = $plugin;
        $this->client = new HttpClient($plugin);
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getClient(): HttpClient
    {
        return $this->client;
    }

    public function createServer(string $host = '0.0.0.0', int $port = 8080): HttpServer
    {
        $this->server = new HttpServer($this->plugin, $host, $port);
        return $this->server;
    }

    public function getServer(): ?HttpServer
    {
        return $this->server;
    }

    public static function create(PluginBase $plugin): self
    {
        return new self($plugin);
    }
}
