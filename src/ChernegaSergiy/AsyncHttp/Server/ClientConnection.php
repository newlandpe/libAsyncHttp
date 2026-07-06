<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

/**
 * Per-connection state for HttpServer's non-blocking poll loop.
 *
 * Lives entirely on the main thread (never touched by an AsyncTask), so it
 * is free to hold a socket resource without any serialization concerns.
 *
 * Lifecycle: READING -> DISPATCHING (transient, inside HttpServer) -> WRITING
 * -> either back to READING (Keep-Alive) or closed.
 */
final class ClientConnection
{
    public const STATE_READING = 'reading';
    public const STATE_WRITING = 'writing';

    /** @var resource */
    public $socket;

    public string $state = self::STATE_READING;

    // --- read side ---
    public string $readBuffer = '';
    public bool $headersComplete = false;
    public int $headerEnd = 0;
    public int $contentLength = 0;
    public string $method = 'GET';
    public string $path = '/';
    public string $protocol = 'HTTP/1.1';
    /** @var array<string, string> */
    public array $headers = [];

    // --- write side ---
    public string $writeBuffer = '';
    public int $writeOffset = 0;
    public bool $keepAliveAfterWrite = false;

    public float $connectedAt;
    public float $lastActivityAt;
    public int $requestsHandled = 0;

    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
        $this->connectedAt = microtime(true);
        $this->lastActivityAt = $this->connectedAt;
    }

    public function bodyReceivedLength(): int
    {
        return max(0, strlen($this->readBuffer) - $this->headerEnd);
    }

    public function isRequestComplete(): bool
    {
        return $this->headersComplete && $this->bodyReceivedLength() >= $this->contentLength;
    }

    public function getBody(): string
    {
        return substr($this->readBuffer, $this->headerEnd, $this->contentLength);
    }

    public function hasPendingWrite(): bool
    {
        return $this->writeOffset < strlen($this->writeBuffer);
    }

    public function beginWrite(string $data, bool $keepAlive): void
    {
        $this->writeBuffer = $data;
        $this->writeOffset = 0;
        $this->keepAliveAfterWrite = $keepAlive;
        $this->state = self::STATE_WRITING;
    }

    /**
     * Resets the read/write state so the connection can be reused for a
     * subsequent request on the same (Keep-Alive) TCP connection.
     */
    public function resetForNextRequest(): void
    {
        $this->readBuffer = '';
        $this->headersComplete = false;
        $this->headerEnd = 0;
        $this->contentLength = 0;
        $this->method = 'GET';
        $this->path = '/';
        $this->protocol = 'HTTP/1.1';
        $this->headers = [];

        $this->writeBuffer = '';
        $this->writeOffset = 0;
        $this->keepAliveAfterWrite = false;

        $this->requestsHandled++;
        $this->lastActivityAt = microtime(true);
        $this->state = self::STATE_READING;
    }
}
