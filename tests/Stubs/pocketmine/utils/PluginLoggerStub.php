<?php

declare(strict_types=1);

namespace pocketmine\utils;

/**
 * Minimal stand-in for PocketMine's PluginLogger. Swallows messages by
 * default so test output stays clean; tests that care can read ->lines.
 */
final class PluginLoggerStub
{
    /** @var string[] */
    public array $lines = [];

    public function info(string $message): void
    {
        $this->lines[] = "[INFO] {$message}";
    }

    public function warning(string $message): void
    {
        $this->lines[] = "[WARNING] {$message}";
    }

    public function error(string $message): void
    {
        $this->lines[] = "[ERROR] {$message}";
    }

    public function debug(string $message): void
    {
        $this->lines[] = "[DEBUG] {$message}";
    }
}
