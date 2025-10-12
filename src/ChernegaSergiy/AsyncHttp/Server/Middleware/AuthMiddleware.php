<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Server\Middleware;

use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;

class AuthMiddleware implements MiddlewareInterface
{
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function __invoke(Request $request, Response $response): void
    {
        $authHeader = $request->getHeader('Authorization');
        
        if ($authHeader === null || !str_starts_with($authHeader, 'Bearer ')) {
            $response->setStatus(401);
            $response->json(['error' => 'Missing or invalid Authorization header']);
            return;
        }

        $token = substr($authHeader, 7);
        
        if ($token !== $this->apiKey) {
            $response->setStatus(401);
            $response->json(['error' => 'Invalid API key']);
            return;
        }
    }
}
