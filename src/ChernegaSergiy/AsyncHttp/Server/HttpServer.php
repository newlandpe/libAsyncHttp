<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

use ChernegaSergiy\AsyncHttp\Exceptions\ServerException;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;

class HttpServer
{
    private PluginBase $plugin;
    private string $host;
    private int $port;
    private Router $router;
    private ?int $serverSocket = null;
    private bool $running = false;
    private array $middlewares = [];

    public function __construct(PluginBase $plugin, string $host = '0.0.0.0', int $port = 8080)
    {
        $this->plugin = $plugin;
        $this->host = $host;
        $this->port = $port;
        $this->router = new Router();
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function addMiddleware(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function start(): void
    {
        if ($this->running) {
            throw new ServerException('HTTP server is already running');
        }

        $this->serverSocket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr
        );

        if ($this->serverSocket === false) {
            throw new ServerException("Failed to start HTTP server: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->running = true;

        $this->plugin->getLogger()->info("HTTP server started on {$this->host}:{$this->port}");

        $this->scheduleServerTask();
    }

    public function stop(): void
    {
        if ($this->serverSocket !== null) {
            fclose($this->serverSocket);
            $this->serverSocket = null;
        }
        $this->running = false;
        $this->plugin->getLogger()->info("HTTP server stopped");
    }

    private function scheduleServerTask(): void
    {
        if (!$this->running) return;

        $this->plugin->getScheduler()->scheduleAsyncTask(
            new class($this->serverSocket, $this->router, $this->middlewares, $this->plugin->getLogger()) extends AsyncTask {
                private $socket;
                private Router $router;
                private array $middlewares;
                private \ThreadedLogger $logger;

                public function __construct($socket, Router $router, array $middlewares, \ThreadedLogger $logger)
                {
                    $this->socket = $socket;
                    $this->router = $router;
                    $this->middlewares = $middlewares;
                    $this->logger = $logger;
                }

                public function onRun(): void
                {
                    $sockets = [$this->socket];
                    $write = [];
                    $except = [];
                    $timeout = 1;

                    while (!empty($sockets)) {
                        $read = $sockets;
                        $numChanged = @stream_select($read, $write, $except, $timeout);

                        if ($numChanged === false) {
                            break;
                        }

                        if ($numChanged > 0) {
                            foreach ($read as $socket) {
                                if ($socket === $this->socket) {
                                    // New connection
                                    $clientSocket = @stream_socket_accept($this->socket, 0);
                                    if ($clientSocket !== false) {
                                        $sockets[] = $clientSocket;
                                        stream_set_blocking($clientSocket, false);
                                    }
                                } else {
                                    // Existing connection
                                    $requestData = @fread($socket, 8192);
                                    
                                    if ($requestData === false || $requestData === '' || feof($socket)) {
                                        // Connection closed
                                        fclose($socket);
                                        $sockets = array_filter($sockets, fn($s) => $s !== $socket);
                                        continue;
                                    }

                                    try {
                                        $request = Request::fromRawData($requestData);
                                        $response = new Response();

                                        // Apply middlewares
                                        foreach ($this->middlewares as $middleware) {
                                            $middleware($request, $response);
                                            if ($response->isSent()) break;
                                        }

                                        if (!$response->isSent()) {
                                            $this->router->handle($request, $response);
                                        }

                                        fwrite($socket, $response->buildResponse());
                                    } catch (\Throwable $e) {
                                        $errorResponse = new Response();
                                        $errorResponse->setStatus(500);
                                        $errorResponse->json([
                                            'error' => 'Internal Server Error',
                                            'message' => $e->getMessage()
                                        ]);
                                        fwrite($socket, $errorResponse->buildResponse());
                                    }

                                    fclose($socket);
                                    $sockets = array_filter($sockets, fn($s) => $s !== $socket);
                                }
                            }
                        }
                    }
                }
            }
        );
    }

    public function __destruct()
    {
        $this->stop();
    }
}
