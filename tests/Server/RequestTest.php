<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Server;

use ChernegaSergiy\AsyncHttp\Server\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testBasicConstruction(): void
    {
        $req = new Request('get', '/foo', ['content-type' => 'application/json'], '{"a":1}');

        $this->assertSame('GET', $req->getMethod(), 'method is normalized to uppercase');
        $this->assertSame('/foo', $req->getPath());
        $this->assertSame('application/json', $req->getHeader('Content-Type'), 'header lookup is case-insensitive');
        $this->assertSame(['a' => 1], $req->json());
    }

    public function testQueryStringIsParsedAndSeparatedFromPath(): void
    {
        $req = new Request('GET', '/search?q=hello+world&page=2');

        $this->assertSame('/search', $req->getPath());
        $this->assertSame('hello world', $req->getQueryParam('q'));
        $this->assertSame('2', $req->getQueryParam('page'));
        $this->assertNull($req->getQueryParam('missing'));
        $this->assertSame('default', $req->getQueryParam('missing', 'default'));
    }

    public function testRouteParams(): void
    {
        $req = new Request('GET', '/users/7');
        $this->assertSame([], $req->getRouteParams());

        $req->setRouteParams(['id' => '7']);
        $this->assertSame('7', $req->getRouteParam('id'));
        $this->assertNull($req->getRouteParam('missing'));
        $this->assertSame('fallback', $req->getRouteParam('missing', 'fallback'));
    }

    public function testInvalidJsonBodyReturnsEmptyArrayInsteadOfThrowing(): void
    {
        $req = new Request('POST', '/x', [], 'not json at all');
        $this->assertSame([], $req->json());
    }

    public function testFromRawDataParsesFullRequestIncludingBody(): void
    {
        $raw = "POST /oauth/token HTTP/1.1\r\nHost: example.com\r\nContent-Type: application/json\r\nContent-Length: 13\r\n\r\n{\"a\":\"12345\"}";
        $req = Request::fromRawData($raw);

        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/oauth/token', $req->getPath());
        $this->assertSame('HTTP/1.1', $req->getProtocol());
        $this->assertSame('example.com', $req->getHeader('Host'));
        $this->assertSame(['a' => '12345'], $req->json());
    }

    public function testFromRawDataThrowsOnMissingHeaderTerminator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Request::fromRawData("GET /x HTTP/1.1\r\nHost: example.com");
    }

    public function testParseHeaderBlobLowercasesHeaderNames(): void
    {
        [$method, $path, $protocol, $headers] = Request::parseHeaderBlob("GET /a HTTP/1.1\r\nX-Custom-Header: Value\r\nHost: example.com");

        $this->assertSame('GET', $method);
        $this->assertSame('/a', $path);
        $this->assertSame('HTTP/1.1', $protocol);
        $this->assertSame('Value', $headers['x-custom-header']);
        $this->assertSame('example.com', $headers['host']);
    }
}
