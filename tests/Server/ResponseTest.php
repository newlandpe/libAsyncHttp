<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Server;

use ChernegaSergiy\AsyncHttp\Server\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testDefaultsTo200(): void
    {
        $res = new Response();
        $res->text('hi');
        $this->assertStringStartsWith('HTTP/1.1 200 OK', $res->buildResponse());
    }

    public function testSetStatusChangesStatusLine(): void
    {
        $res = new Response();
        $res->setStatus(404)->json(['error' => 'not found']);
        $this->assertStringStartsWith('HTTP/1.1 404 Not Found', $res->buildResponse());
    }

    public function testJsonSetsContentTypeAndBody(): void
    {
        $res = new Response();
        $res->json(['a' => 1]);
        $raw = $res->buildResponse();

        $this->assertStringContainsString('Content-Type: application/json', $raw);
        [, $body] = explode("\r\n\r\n", $raw, 2);
        $this->assertSame(['a' => 1], json_decode($body, true));
    }

    public function testContentLengthMatchesActualBodyByteLength(): void
    {
        $res = new Response();
        $res->text('héllo'); // multi-byte body: Content-Length must be bytes, not characters
        $raw = $res->buildResponse();

        preg_match('/Content-Length: (\d+)/', $raw, $m);
        [, $body] = explode("\r\n\r\n", $raw, 2);

        $this->assertSame(strlen($body), (int) $m[1]);
    }

    public function testDefaultsToConnectionCloseWhenNotSetExplicitly(): void
    {
        $res = new Response();
        $res->text('hi');
        $this->assertStringContainsString('Connection: close', $res->buildResponse());
    }

    public function testCallerCanOverrideConnectionHeaderForKeepAlive(): void
    {
        $res = new Response();
        $res->setHeader('Connection', 'keep-alive');
        $res->text('hi');
        $raw = $res->buildResponse();

        $this->assertStringContainsString('Connection: keep-alive', $raw);
        $this->assertSame(1, substr_count($raw, 'Connection:'), 'must not emit Connection header twice');
    }

    public function testCustomHeadersArePreserved(): void
    {
        $res = new Response();
        $res->setHeader('X-Request-Id', 'abc-123')->json(['ok' => true]);
        $this->assertStringContainsString('X-Request-Id: abc-123', $res->buildResponse());
    }

    public function testIsSentTracksWhetherBodyWasWritten(): void
    {
        $res = new Response();
        $this->assertFalse($res->isSent());
        $res->json(['ok' => true]);
        $this->assertTrue($res->isSent());
    }
}
