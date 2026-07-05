<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

/**
 * Per-connection read state for HttpServer's non-blocking poll loop.
 * Lives entirely on the main thread (it is never touched by an AsyncTask),
 * so it is free to hold a socket resource without any serialization
 * concerns.
 */
final class ClientConnection
{
    /** @var resource */
    public $socket;
    public string $buffer = '';
    public bool $headersComplete = false;
    public int $headerEnd = 0;
    public int $contentLength = 0;
    public string $method = 'GET';
    public string $path = '/';
    public string $protocol = 'HTTP/1.1';
    /** @var array<string, string> */
    public array $headers = [];
    public float $connectedAt;

    /**
     * @param resource $socket
     */
    public function __construct($socket)
    {
        $this->socket = $socket;
        $this->connectedAt = microtime(true);
    }

    public function bodyReceivedLength(): int
    {
        return max(0, strlen($this->buffer) - $this->headerEnd);
    }

    public function isComplete(): bool
    {
        return $this->headersComplete && $this->bodyReceivedLength() >= $this->contentLength;
    }

    public function getBody(): string
    {
        return substr($this->buffer, $this->headerEnd, $this->contentLength);
    }
}
