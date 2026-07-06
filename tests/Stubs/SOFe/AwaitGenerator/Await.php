<?php

declare(strict_types=1);

namespace SOFe\AwaitGenerator;

use Closure;
use Generator;
use Throwable;

/**
 * Minimal test stand-in for sof3/await-generator's Await::promise().
 *
 * Since the AsyncPool stub runs tasks synchronously, resolve()/reject() are
 * always called before this generator is even iterated, so a real
 * scheduler/event-loop isn't needed to make `yield from Await::promise(...)`
 * work correctly in tests. This is intentionally NOT a full await-generator
 * reimplementation — it only supports what HttpClient needs.
 */
final class Await
{
    public static function promise(Closure $executor): Generator
    {
        $done = false;
        $value = null;
        $error = null;

        $resolve = function ($v) use (&$done, &$value): void {
            $done = true;
            $value = $v;
        };
        $reject = function (Throwable $e) use (&$done, &$error): void {
            $done = true;
            $error = $e;
        };

        $executor($resolve, $reject);

        while (!$done) {
            yield;
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }
}
