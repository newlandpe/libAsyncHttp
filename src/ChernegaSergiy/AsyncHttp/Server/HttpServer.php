<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

use ChernegaSergiy\AsyncHttp\Exceptions\ServerException;
use ChernegaSergiy\AsyncHttp\Server\Middleware\MiddlewareInterface;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;

/**
 * A lightweight embedded HTTP server for PocketMine plugins (e.g. an OAuth
 * 2.0 callback endpoint).
 *
 * Architecture note: this server does NOT use AsyncTask. AsyncTask exists
 * for "run once on a worker, return a result", not for a long-lived socket
 * server, and everything it would need to hold (a Router full of Closures,
 * middleware callables, the plugin logger, the raw socket resource) is
 * either non-serializable or simply not meant to leave the main thread.
 *
 * Instead, the accept/read/write loop is driven by a normal repeating
 * scheduler Task (ServerPollTask) that runs on the main thread once per
 * tick. Every socket call inside poll() uses a zero-timeout select/read, so
 * it never blocks the server; connections are read incrementally across
 * ticks and a proper Content-Length-aware state machine (ClientConnection)
 * assembles the full request before dispatching it.
 */
class HttpServer
{
    private PluginBase $plugin;
    private string $host;
    private int $port;
    private Router $router;

    /** @var resource|null */
    private $serverSocket = null;
    private bool $running = false;

    /** @var MiddlewareInterface[] */
    private array $middlewares = [];

    private ?TaskHandler $taskHandler = null;

    /** @var array<int, ClientConnection> keyed by (int) socket */
    private array $connections = [];

    private int $connectionTimeoutSeconds;
    private int $maxHeaderSize;
    private int $maxBodySize;

    public function __construct(
        PluginBase $plugin,
        string $host = '0.0.0.0',
        int $port = 8080,
        int $connectionTimeoutSeconds = 15,
        int $maxHeaderSize = 16384,
        int $maxBodySize = 1048576
    ) {
        $this->plugin = $plugin;
        $this->host = $host;
        $this->port = $port;
        $this->router = new Router();
        $this->connectionTimeoutSeconds = $connectionTimeoutSeconds;
        $this->maxHeaderSize = $maxHeaderSize;
        $this->maxBodySize = $maxBodySize;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function addMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function start(): void
    {
        if ($this->running) {
            throw new ServerException('HTTP server is already running');
        }

        $socket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr
        );

        if ($socket === false) {
            throw new ServerException("Failed to start HTTP server: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);
        $this->serverSocket = $socket;
        $this->running = true;

        $this->plugin->getLogger()->info("HTTP server started on {$this->host}:{$this->port}");

        // Runs on the main thread, once per tick. See ServerPollTask docblock.
        $this->taskHandler = $this->plugin->getScheduler()->scheduleRepeatingTask(new ServerPollTask($this), 1);
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->taskHandler?->cancel();
        $this->taskHandler = null;

        foreach ($this->connections as $connection) {
            @fclose($connection->socket);
        }
        $this->connections = [];

        if ($this->serverSocket !== null) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }

        $this->running = false;
        $this->plugin->getLogger()->info('HTTP server stopped');
    }

    /**
     * Called by ServerPollTask every tick. Never blocks: accept and
     * stream_select both use a 0-second timeout, and reads only consume
     * whatever is already buffered by the OS.
     */
    public function poll(): void
    {
        if (!$this->running || $this->serverSocket === null) {
            return;
        }

        while (($client = @stream_socket_accept($this->serverSocket, 0)) !== false) {
            stream_set_blocking($client, false);
            $this->connections[(int) $client] = new ClientConnection($client);
        }

        $this->reapTimedOutConnections();

        if (empty($this->connections)) {
            return;
        }

        $read = [];
        foreach ($this->connections as $id => $connection) {
            $read[$id] = $connection->socket;
        }
        $write = [];
        $except = [];

        $changed = @stream_select($read, $write, $except, 0);
        if ($changed === false || $changed === 0) {
            return;
        }

        foreach ($read as $id => $socket) {
            $this->handleReadable($id);
        }
    }

    private function reapTimedOutConnections(): void
    {
        if (empty($this->connections)) {
            return;
        }

        $now = microtime(true);
        foreach ($this->connections as $id => $connection) {
            if ($now - $connection->connectedAt > $this->connectionTimeoutSeconds) {
                $this->respondAndClose($connection, 408, 'Request Timeout', ['error' => 'Request Timeout']);
                unset($this->connections[$id]);
            }
        }
    }

    private function handleReadable(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null) {
            return;
        }

        $chunk = @fread($connection->socket, 65536);

        if ($chunk === false || ($chunk === '' && feof($connection->socket))) {
            @fclose($connection->socket);
            unset($this->connections[$id]);
            return;
        }

        $connection->buffer .= $chunk;

        if (!$connection->headersComplete) {
            $headerEndPos = strpos($connection->buffer, "\r\n\r\n");

            if ($headerEndPos === false) {
                if (strlen($connection->buffer) > $this->maxHeaderSize) {
                    $this->respondAndClose($connection, 431, 'Request Header Fields Too Large', ['error' => 'Request Header Fields Too Large']);
                    unset($this->connections[$id]);
                }
                return;
            }

            $headerBlob = substr($connection->buffer, 0, $headerEndPos);
            [$connection->method, $connection->path, $connection->protocol, $connection->headers] = Request::parseHeaderBlob($headerBlob);
            $connection->headersComplete = true;
            $connection->headerEnd = $headerEndPos + 4;
            $connection->contentLength = (int) ($connection->headers['content-length'] ?? 0);

            if ($connection->contentLength > $this->maxBodySize) {
                $this->respondAndClose($connection, 413, 'Payload Too Large', ['error' => 'Payload Too Large']);
                unset($this->connections[$id]);
                return;
            }
        }

        if ($connection->isComplete()) {
            $this->dispatch($connection);
            @fclose($connection->socket);
            unset($this->connections[$id]);
        }
    }

    private function dispatch(ClientConnection $connection): void
    {
        $request = new Request($connection->method, $connection->path, $connection->headers, $connection->getBody(), $connection->protocol);
        $response = new Response();

        try {
            $pipeline = new MiddlewarePipeline($this->middlewares, function (Request $request, Response $response): void {
                $this->router->handle($request, $response);
            });
            $pipeline->handle($request, $response);
        } catch (\Throwable $e) {
            $response = new Response();
            $response->setStatus(500);
            $response->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
            ]);
        }

        @fwrite($connection->socket, $response->buildResponse());
    }

    private function respondAndClose(ClientConnection $connection, int $status, string $reason, array $payload): void
    {
        $response = new Response();
        $response->setStatus($status);
        $response->json($payload);
        @fwrite($connection->socket, $response->buildResponse());
        @fclose($connection->socket);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
