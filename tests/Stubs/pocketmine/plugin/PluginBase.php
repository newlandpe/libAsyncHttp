<?php

declare(strict_types=1);

namespace pocketmine\plugin;

use pocketmine\scheduler\TaskScheduler;
use pocketmine\Server;
use pocketmine\utils\PluginLoggerStub;

/**
 * Test stand-in for pocketmine\plugin\PluginBase — just enough surface
 * area (getLogger/getScheduler/getServer) for HttpClient and HttpServer to
 * run against in tests.
 */
class PluginBase
{
    private TaskScheduler $scheduler;
    private Server $server;
    private PluginLoggerStub $logger;

    public function __construct()
    {
        $this->scheduler = new TaskScheduler();
        $this->server = new Server();
        $this->logger = new PluginLoggerStub();
    }

    public function getScheduler(): TaskScheduler
    {
        return $this->scheduler;
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getLogger(): PluginLoggerStub
    {
        return $this->logger;
    }
}
