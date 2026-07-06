<?php

declare(strict_types=1);

namespace pocketmine\utils;

final class InternetRequestResult
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private array $headers,
        private string $body,
        private int $code
    ) {
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCode(): int
    {
        return $this->code;
    }
}
