<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

/**
 * Minimal test stand-in for pocketmine\scheduler\Task, sufficient to run
 * HttpServer/ServerPollTask logic in isolation without a real PocketMine
 * server. Tests call HttpServer::poll() directly rather than relying on a
 * real scheduler tick loop.
 */
abstract class Task
{
    abstract public function onRun(): void;
}
