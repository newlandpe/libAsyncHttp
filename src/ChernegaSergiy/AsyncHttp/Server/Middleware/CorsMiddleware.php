<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server\Middleware;

use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;

class CorsMiddleware implements MiddlewareInterface
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;
    private int $maxAge;

    public function __construct(array $allowedOrigins = ['*'], array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'], array $allowedHeaders = ['Content-Type', 'Authorization'], int $maxAge = 86400)
    {
        $this->allowedOrigins = $allowedOrigins;
        $this->allowedMethods = $allowedMethods;
        $this->allowedHeaders = $allowedHeaders;
        $this->maxAge = $maxAge;
    }

    public function process(Request $request, Response $response, callable $next): void
    {
        $origin = $request->getHeader('Origin');

        if ($origin !== null) {
            if (in_array('*', $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true)) {
                $response->setHeader('Access-Control-Allow-Origin', in_array('*', $this->allowedOrigins, true) ? '*' : $origin);
            }
            $response->setHeader('Vary', 'Origin');
        }

        if ($request->getMethod() === 'OPTIONS') {
            $response->setHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
            $response->setHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
            $response->setHeader('Access-Control-Max-Age', (string) $this->maxAge);
            $response->setStatus(204);
            $response->send('');
            return;
        }

        $next($request, $response);
    }
}
