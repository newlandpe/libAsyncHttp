<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server;

use ChernegaSergiy\AsyncHttp\Server\Middleware\MiddlewareInterface;

/**
 * Builds a single callable chain out of an ordered list of middlewares plus
 * a final handler (the router):
 *
 *   Auth -> Cors -> Logger -> Router::handle
 *
 * Each middleware decides whether to call $next() to continue the chain,
 * or to stop by writing directly to the response (e.g. a 401).
 */
final class MiddlewarePipeline
{
    /** @var callable(Request, Response): void */
    private $chain;

    /**
     * @param MiddlewareInterface[] $middlewares
     * @param callable(Request, Response): void $finalHandler
     */
    public function __construct(array $middlewares, callable $finalHandler)
    {
        $next = $finalHandler;

        foreach (array_reverse($middlewares) as $middleware) {
            $next = static function (Request $request, Response $response) use ($middleware, $next): void {
                $middleware->process($request, $response, $next);
            };
        }

        $this->chain = $next;
    }

    public function handle(Request $request, Response $response): void
    {
        ($this->chain)($request, $response);
    }
}
