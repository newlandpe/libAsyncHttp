<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use ChernegaSergiy\AsyncHttp\Exceptions\InvalidResponseException;
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

    public function status(): int
    {
        return $this->getStatusCode();
    }

    public function getBody(): string
    {
        return $this->result->getBody();
    }

    public function text(): string
    {
        return $this->getBody();
    }

    public function json(): array
    {
        $data = json_decode($this->result->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidResponseException('JSON decode error: ' . json_last_error_msg());
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

    public function header(string $name): ?string
    {
        return $this->getHeader($name);
    }

    /**
     * Parses Set-Cookie header(s) into a simple name => value map.
     * Cookie attributes (Path, HttpOnly, ...) are intentionally discarded;
     * this is meant for reading a value back, not for cookie-jar semantics.
     */
    public function cookies(): array
    {
        $raw = $this->getHeaders()['set-cookie'] ?? null;
        if ($raw === null) {
            return [];
        }

        $cookies = [];
        foreach ((array) $raw as $cookieLine) {
            $firstPart = explode(';', $cookieLine, 2)[0];
            $eq = strpos($firstPart, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($firstPart, 0, $eq));
            $value = trim(substr($firstPart, $eq + 1));
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    public function isSuccess(): bool
    {
        return $this->getStatusCode() >= 200 && $this->getStatusCode() < 300;
    }

    public function ok(): bool
    {
        return $this->getStatusCode() === 200;
    }

    public function successful(): bool
    {
        return $this->isSuccess();
    }

    public function failed(): bool
    {
        return !$this->isSuccess();
    }
}
