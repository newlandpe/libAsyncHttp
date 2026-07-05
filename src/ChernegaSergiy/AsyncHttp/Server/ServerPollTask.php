<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

use pocketmine\scheduler\Task;

/**
 * A normal (main-thread) scheduler Task, NOT an AsyncTask.
 *
 * This is the crux of the server-side fix: the previous implementation ran
 * its accept/read loop inside an AsyncTask, which meant the Router (full of
 * Closures), the middleware callables, the PluginBase logger and even the
 * raw socket resource all had to survive a trip to a worker thread — none
 * of which is possible in PocketMine.
 *
 * A Task, by contrast, always runs on the main thread. Holding a reference
 * to HttpServer (and everything it owns) here is completely safe. The loop
 * itself never blocks: every socket operation uses a 0-second select/read,
 * so a full poll() call costs microseconds even under load, and the task is
 * simply re-invoked every tick by the scheduler instead of looping forever
 * inside onRun().
 */
final class ServerPollTask extends Task
{
    private HttpServer $server;

    public function __construct(HttpServer $server)
    {
        $this->server = $server;
    }

    public function onRun(): void
    {
        $this->server->poll();
    }
}
