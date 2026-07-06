<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Server;

use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;
use ChernegaSergiy\AsyncHttp\Server\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function decodeJson(Response $response): array
    {
        [, $body] = explode("\r\n\r\n", $response->buildResponse(), 2);
        return json_decode($body, true);
    }

    private function statusOf(Response $response): int
    {
        preg_match('#^HTTP/1\.1 (\d+)#', $response->buildResponse(), $m);
        return (int) $m[1];
    }

    public function testStaticRoute(): void
    {
        $router = new Router();
        $router->get('/health', fn(Request $req, Response $res) => $res->json(['ok' => true]));

        $res = new Response();
        $router->handle(new Request('GET', '/health'), $res);

        $this->assertSame(200, $this->statusOf($res));
        $this->assertSame(['ok' => true], $this->decodeJson($res));
    }

    public function testParameterRoute(): void
    {
        $router = new Router();
        $router->get('/users/{id}', fn(Request $req, Response $res) => $res->json(['id' => $req->getRouteParam('id')]));

        $res = new Response();
        $router->handle(new Request('GET', '/users/42'), $res);

        $this->assertSame(['id' => '42'], $this->decodeJson($res));
    }

    public function testMultipleParamsRoute(): void
    {
        $router = new Router();
        $router->get('/orgs/{org}/repos/{repo}', function (Request $req, Response $res) {
            $res->json(['org' => $req->getRouteParam('org'), 'repo' => $req->getRouteParam('repo')]);
        });

        $res = new Response();
        $router->handle(new Request('GET', '/orgs/anthropic/repos/claude'), $res);

        $this->assertSame(['org' => 'anthropic', 'repo' => 'claude'], $this->decodeJson($res));
    }

    public function testOverlappingRoutesUseFirstRegisteredMatch(): void
    {
        $router = new Router();
        $router->get('/users/me', fn(Request $req, Response $res) => $res->json(['matched' => 'static']));
        $router->get('/users/{id}', fn(Request $req, Response $res) => $res->json(['matched' => 'param']));

        $res = new Response();
        $router->handle(new Request('GET', '/users/me'), $res);

        $this->assertSame(['matched' => 'static'], $this->decodeJson($res), 'more specific route registered first must win');
    }

    public function testNotFoundReturns404(): void
    {
        $router = new Router();
        $router->get('/users', fn(Request $req, Response $res) => $res->json(['ok' => true]));

        $res = new Response();
        $router->handle(new Request('GET', '/does-not-exist'), $res);

        $this->assertSame(404, $this->statusOf($res));
    }

    public function testMethodMismatchIsNotFound(): void
    {
        $router = new Router();
        $router->post('/users', fn(Request $req, Response $res) => $res->json(['created' => true]));

        $res = new Response();
        $router->handle(new Request('GET', '/users'), $res);

        $this->assertSame(404, $this->statusOf($res));
    }

    public function testAnyRegistersAllCommonMethods(): void
    {
        $router = new Router();
        $router->any('/ping', fn(Request $req, Response $res) => $res->json(['method' => $req->getMethod()]));

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $res = new Response();
            $router->handle(new Request($method, '/ping'), $res);
            $this->assertSame(['method' => $method], $this->decodeJson($res));
        }
    }
}
