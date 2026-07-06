<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\scheduler\AsyncPool;

final class Server
{
    private AsyncPool $asyncPool;

    public function __construct()
    {
        $this->asyncPool = new AsyncPool();
    }

    public function getAsyncPool(): AsyncPool
    {
        return $this->asyncPool;
    }
}
