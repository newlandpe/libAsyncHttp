<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server\Middleware;

use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;

interface MiddlewareInterface
{
    /**
     * Call $next($request, $response) to continue down the pipeline
     * (Auth -> Cors -> Logger -> Handler). Do NOT call it to short-circuit
     * the chain, e.g. after writing a 401 response.
     *
     * @param callable(Request, Response): void $next
     */
    public function process(Request $request, Response $response, callable $next): void;
}
