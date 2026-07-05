<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private string $body = '';
    private bool $sent = false;

    public function setStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(array $data): self
    {
        $this->setHeader('Content-Type', 'application/json');
        $this->body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->sent = true;
        return $this;
    }

    public function text(string $text): self
    {
        $this->setHeader('Content-Type', 'text/plain');
        $this->body = $text;
        $this->sent = true;
        return $this;
    }

    public function html(string $html): self
    {
        $this->setHeader('Content-Type', 'text/html');
        $this->body = $html;
        $this->sent = true;
        return $this;
    }

    public function send(string $body, string $contentType = 'text/plain'): self
    {
        $this->setHeader('Content-Type', $contentType);
        $this->body = $body;
        $this->sent = true;
        return $this;
    }

    public function isSent(): bool
    {
        return $this->sent;
    }

    public function buildResponse(): string
    {
        $statusText = $this->getStatusText($this->statusCode);
        $headers = ["HTTP/1.1 {$this->statusCode} {$statusText}"];

        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $headers[] = "Content-Length: " . strlen($this->body);
        $headers[] = "Connection: close";

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->body;
    }

    private function getStatusText(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            409 => 'Conflict',
            413 => 'Payload Too Large',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            431 => 'Request Header Fields Too Large',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Unknown Status',
        };
    }
}
