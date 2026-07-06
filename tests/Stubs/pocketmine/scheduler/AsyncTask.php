<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

/**
 * Minimal test stand-in for pocketmine\scheduler\AsyncTask.
 *
 * Real PocketMine runs onRun() on a worker thread and onCompletion() back
 * on the main thread, and storeLocal()/fetchLocal() are the sanctioned way
 * to smuggle non-thread-safe data (like Closures) across that boundary
 * without ever serializing it. This stub executes everything synchronously
 * in a single process/thread (see AsyncPool stub), which is enough to
 * verify the *wiring* — that storeLocal() data really does make it back to
 * onCompletion() and gets invoked correctly — even though it can't
 * reproduce real multi-threaded serialization constraints.
 */
abstract class AsyncTask
{
    private mixed $localData = null;
    private mixed $result = null;

    public function storeLocal(mixed $complexData): void
    {
        $this->localData = $complexData;
    }

    public function fetchLocal(): mixed
    {
        return $this->localData;
    }

    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    abstract public function onRun(): void;

    public function onCompletion(): void
    {
    }
}
