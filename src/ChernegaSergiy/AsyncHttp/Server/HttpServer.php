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
 * it never blocks the server.
 *
 * Each connection goes through three phases (see ClientConnection):
 *
 *   READING -> (headers + Content-Length-aware body assembly)
 *       |
 *       v
 *   DISPATCH (middleware pipeline + router, synchronous, main thread)
 *       |
 *       v
 *   WRITING -> fwrite() is attempted every tick until writeBuffer is fully
 *              flushed (non-blocking sockets can accept fewer bytes than
 *              requested in a single call, so partial writes are tracked
 *              via writeOffset instead of being silently dropped)
 *       |
 *       v
 *   Connection: keep-alive -> reset -> back to READING
 *   Connection: close      -> fclose()
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
    private int $maxKeepAliveRequests;

    public function __construct(
        PluginBase $plugin,
        string $host = '0.0.0.0',
        int $port = 8080,
        int $connectionTimeoutSeconds = 15,
        int $maxHeaderSize = 16384,
        int $maxBodySize = 1048576,
        int $maxKeepAliveRequests = 100
    ) {
        $this->plugin = $plugin;
        $this->host = $host;
        $this->port = $port;
        $this->router = new Router();
        $this->connectionTimeoutSeconds = $connectionTimeoutSeconds;
        $this->maxHeaderSize = $maxHeaderSize;
        $this->maxBodySize = $maxBodySize;
        $this->maxKeepAliveRequests = $maxKeepAliveRequests;
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
     * Called by ServerPollTask every tick. Never blocks: accept, select and
     * fwrite all either use a 0-second timeout or are inherently
     * non-blocking, and reads/writes only move whatever the OS already has
     * buffered.
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
        $this->flushPendingWrites();

        if (empty($this->connections)) {
            return;
        }

        $read = [];
        foreach ($this->connections as $id => $connection) {
            if ($connection->state === ClientConnection::STATE_READING) {
                $read[$id] = $connection->socket;
            }
        }

        if (empty($read)) {
            return;
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

    /**
     * Attempts to flush any connection currently in the WRITING phase.
     * Safe to call unconditionally every tick: fwrite() on a non-blocking
     * socket returns immediately, and we only advance writeOffset by
     * however many bytes it actually accepted.
     */
    private function flushPendingWrites(): void
    {
        foreach ($this->connections as $id => $connection) {
            if ($connection->state !== ClientConnection::STATE_WRITING) {
                continue;
            }

            $remaining = substr($connection->writeBuffer, $connection->writeOffset);
            if ($remaining === '') {
                $this->finishWrite($id, $connection);
                continue;
            }

            $written = @fwrite($connection->socket, $remaining);

            if ($written === false) {
                @fclose($connection->socket);
                unset($this->connections[$id]);
                continue;
            }

            $connection->writeOffset += $written;
            $connection->lastActivityAt = microtime(true);

            if ($connection->writeOffset >= strlen($connection->writeBuffer)) {
                $this->finishWrite($id, $connection);
            }
        }
    }

    private function finishWrite(int $id, ClientConnection $connection): void
    {
        if ($connection->keepAliveAfterWrite && $connection->requestsHandled + 1 < $this->maxKeepAliveRequests) {
            $connection->resetForNextRequest();
            return;
        }

        @fclose($connection->socket);
        unset($this->connections[$id]);
    }

    private function reapTimedOutConnections(): void
    {
        if (empty($this->connections)) {
            return;
        }

        $now = microtime(true);
        foreach ($this->connections as $id => $connection) {
            if ($connection->state !== ClientConnection::STATE_READING) {
                continue;
            }

            if ($now - $connection->lastActivityAt > $this->connectionTimeoutSeconds) {
                if ($connection->readBuffer === '' && $connection->requestsHandled > 0) {
                    // An idle Keep-Alive connection that simply never sent
                    // another request: nothing to respond to, just drop it.
                    @fclose($connection->socket);
                } else {
                    // A request started (any bytes received) but never
                    // completed in time — this deserves an actual response,
                    // whether it's the very first request on this
                    // connection or a later one on a Keep-Alive socket.
                    $this->writeErrorAndClose($connection, 408, 'Request Timeout', ['error' => 'Request Timeout']);
                }
                unset($this->connections[$id]);
            }
        }
    }

    private function handleReadable(int $id): void
    {
        $connection = $this->connections[$id] ?? null;
        if ($connection === null || $connection->state !== ClientConnection::STATE_READING) {
            return;
        }

        $chunk = @fread($connection->socket, 65536);

        if ($chunk === false || ($chunk === '' && feof($connection->socket))) {
            @fclose($connection->socket);
            unset($this->connections[$id]);
            return;
        }

        if ($chunk === '') {
            return;
        }

        $connection->readBuffer .= $chunk;
        $connection->lastActivityAt = microtime(true);

        if (!$connection->headersComplete) {
            $headerEndPos = strpos($connection->readBuffer, "\r\n\r\n");

            if ($headerEndPos === false) {
                if (strlen($connection->readBuffer) > $this->maxHeaderSize) {
                    $this->writeErrorAndClose($connection, 431, 'Request Header Fields Too Large', ['error' => 'Request Header Fields Too Large']);
                    unset($this->connections[$id]);
                }
                return;
            }

            $headerBlob = substr($connection->readBuffer, 0, $headerEndPos);
            [$connection->method, $connection->path, $connection->protocol, $connection->headers] = Request::parseHeaderBlob($headerBlob);
            $connection->headersComplete = true;
            $connection->headerEnd = $headerEndPos + 4;
            $connection->contentLength = (int) ($connection->headers['content-length'] ?? 0);

            if ($connection->contentLength > $this->maxBodySize) {
                $this->writeErrorAndClose($connection, 413, 'Payload Too Large', ['error' => 'Payload Too Large']);
                unset($this->connections[$id]);
                return;
            }
        }

        if ($connection->isRequestComplete()) {
            $this->dispatch($connection);
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

        $keepAlive = $this->shouldKeepAlive($request);
        $response->setHeader('Connection', $keepAlive ? 'keep-alive' : 'close');

        $connection->beginWrite($response->buildResponse(), $keepAlive);
    }

    private function shouldKeepAlive(Request $request): bool
    {
        $connectionHeader = strtolower($request->getHeader('Connection') ?? '');

        if ($connectionHeader === 'close') {
            return false;
        }

        if ($connectionHeader === 'keep-alive') {
            return true;
        }

        // HTTP/1.1 defaults to persistent connections; HTTP/1.0 defaults to close.
        return $request->getProtocol() === 'HTTP/1.1';
    }

    private function writeErrorAndClose(ClientConnection $connection, int $status, string $reason, array $payload): void
    {
        $response = new Response();
        $response->setStatus($status);
        $response->setHeader('Connection', 'close');
        $response->json($payload);
        @fwrite($connection->socket, $response->buildResponse());
        @fclose($connection->socket);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
