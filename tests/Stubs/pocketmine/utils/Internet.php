<?php

declare(strict_types=1);

namespace pocketmine\utils;

/**
 * Test stub. Production code always talks to the real pocketmine\utils\Internet;
 * this stub lets tests intercept simpleCurl() and return a canned result
 * instead of making a real network call.
 */
final class Internet
{
    /** @var (callable(string, int, array, array): InternetRequestResult)|null */
    public static $mock = null;

    public static function simpleCurl(string $page, int $timeout = 10, array $extraHeaders = [], array $extraOpts = []): InternetRequestResult
    {
        if (self::$mock === null) {
            throw new \RuntimeException('Internet::simpleCurl stub was not mocked for this test');
        }

        return (self::$mock)($page, $timeout, $extraHeaders, $extraOpts);
    }
}
