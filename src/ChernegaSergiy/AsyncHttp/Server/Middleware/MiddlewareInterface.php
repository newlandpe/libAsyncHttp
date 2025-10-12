<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server\Middleware;

use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;

interface MiddlewareInterface
{
    public function __invoke(Request $request, Response $response): void;
}
