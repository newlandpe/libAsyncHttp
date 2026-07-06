<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

/**
 * Runs submitted tasks synchronously in-process. See AsyncTask stub
 * docblock for what this can and cannot verify.
 */
final class AsyncPool
{
    public function submitTask(AsyncTask $task): void
    {
        $task->onRun();
        $task->onCompletion();
    }
}
