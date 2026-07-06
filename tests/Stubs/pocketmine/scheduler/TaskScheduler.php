<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

final class TaskScheduler
{
    /** @var Task[] */
    private array $tasks = [];

    public function scheduleRepeatingTask(Task $task, int $period): TaskHandler
    {
        $this->tasks[] = $task;
        return new TaskHandler();
    }
}
