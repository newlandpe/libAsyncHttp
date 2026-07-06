<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Server;

use ChernegaSergiy\AsyncHttp\Server\MiddlewarePipeline;
use ChernegaSergiy\AsyncHttp\Server\Middleware\MiddlewareInterface;
use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;
use PHPUnit\Framework\TestCase;

final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private string $name, private array &$log)
    {
    }

    public function process(Request $request, Response $response, callable $next): void
    {
        $this->log[] = "{$this->name}:before";
        $next($request, $response);
        $this->log[] = "{$this->name}:after";
    }
}

final class BlockingMiddleware implements MiddlewareInterface
{
    public function __construct(private array &$log)
    {
    }

    public function process(Request $request, Response $response, callable $next): void
    {
        $this->log[] = 'blocker';
        $response->setStatus(401)->json(['error' => 'blocked']);
        // deliberately does not call $next()
    }
}

final class MiddlewarePipelineTest extends TestCase
{
    public function testMiddlewaresRunInOrderThenHandler(): void
    {
        $log = [];
        $pipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('auth', $log), new RecordingMiddleware('cors', $log)],
            function (Request $req, Response $res) use (&$log) {
                $log[] = 'handler';
                $res->json(['ok' => true]);
            }
        );

        $pipeline->handle(new Request('GET', '/x'), new Response());

        $this->assertSame(
            ['auth:before', 'cors:before', 'handler', 'cors:after', 'auth:after'],
            $log,
            'each middleware wraps the next, onion-style'
        );
    }

    public function testMiddlewareCanStopThePropagation(): void
    {
        $log = [];
        $handlerCalled = false;

        $pipeline = new MiddlewarePipeline(
            [new RecordingMiddleware('auth', $log), new BlockingMiddleware($log)],
            function (Request $req, Response $res) use (&$handlerCalled) {
                $handlerCalled = true;
            }
        );

        $response = new Response();
        $pipeline->handle(new Request('GET', '/x'), $response);

        $this->assertFalse($handlerCalled, 'handler must never run once a middleware short-circuits');
        $this->assertSame(
            ['auth:before', 'blocker', 'auth:after'],
            $log,
            'auth:after still runs on unwind (onion-style) even though the handler never ran — '
            . 'only the downstream call from "blocker" onward was skipped'
        );
        $this->assertStringStartsWith('HTTP/1.1 401', $response->buildResponse());
    }

    public function testEmptyMiddlewareListCallsHandlerDirectly(): void
    {
        $called = false;
        $pipeline = new MiddlewarePipeline([], function () use (&$called) {
            $called = true;
        });

        $pipeline->handle(new Request('GET', '/x'), new Response());

        $this->assertTrue($called);
    }
}
