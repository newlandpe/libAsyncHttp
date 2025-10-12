<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use pocketmine\utils\InternetRequestResult;

class Response
{
    private InternetRequestResult $result;

    public function __construct(InternetRequestResult $result)
    {
        $this->result = $result;
    }

    public function getStatusCode(): int
    {
        return $this->result->getCode();
    }

    public function getBody(): string
    {
        return $this->result->getBody();
    }

    public function json(): array
    {
        $data = json_decode($this->result->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
        }
        return $data;
    }

    public function getHeaders(): array
    {
        return $this->result->getHeaders();
    }

    public function getHeader(string $name): ?string
    {
        return $this->result->getHeaders()[strtolower($name)] ?? null;
    }

    public function isSuccess(): bool
    {
        return $this->result->getCode() >= 200 && $this->result->getCode() < 300;
    }
}
