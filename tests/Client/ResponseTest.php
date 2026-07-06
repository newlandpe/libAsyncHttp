<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Client;

use ChernegaSergiy\AsyncHttp\Client\Response;
use ChernegaSergiy\AsyncHttp\Exceptions\InvalidResponseException;
use PHPUnit\Framework\TestCase;
use pocketmine\utils\InternetRequestResult;

final class ResponseTest extends TestCase
{
    private function make(int $code, string $body, array $headers = []): Response
    {
        return new Response(new InternetRequestResult($headers, $body, $code));
    }

    public function testStatusHelpers(): void
    {
        $res = $this->make(200, '{}');
        $this->assertTrue($res->ok());
        $this->assertTrue($res->successful());
        $this->assertFalse($res->failed());
        $this->assertSame(200, $res->status());
    }

    public function testNon200SuccessIsSuccessfulButNotOk(): void
    {
        $res = $this->make(201, '{}');
        $this->assertFalse($res->ok(), 'ok() is strictly 200');
        $this->assertTrue($res->successful(), '2xx is successful');
    }

    public function testFailureStatus(): void
    {
        $res = $this->make(404, '{"error":"not found"}');
        $this->assertTrue($res->failed());
        $this->assertFalse($res->successful());
    }

    public function testJsonDecoding(): void
    {
        $res = $this->make(200, '{"access_token":"abc","expires_in":3600}');
        $this->assertSame(['access_token' => 'abc', 'expires_in' => 3600], $res->json());
    }

    public function testJsonThrowsOnInvalidBody(): void
    {
        $res = $this->make(200, 'not json');
        $this->expectException(InvalidResponseException::class);
        $res->json();
    }

    public function testTextReturnsRawBody(): void
    {
        $res = $this->make(200, 'plain text body');
        $this->assertSame('plain text body', $res->text());
    }

    public function testHeaderLookup(): void
    {
        $res = $this->make(200, '{}', ['x-request-id' => 'req-1']);
        $this->assertSame('req-1', $res->header('X-Request-Id'));
        $this->assertNull($res->header('missing'));
    }

    public function testCookiesParsesNameValuePairsAndIgnoresAttributes(): void
    {
        $res = $this->make(200, '{}', [
            'set-cookie' => ['session=abc123; Path=/; HttpOnly', 'theme=dark; Max-Age=3600'],
        ]);

        $this->assertSame(['session' => 'abc123', 'theme' => 'dark'], $res->cookies());
    }

    public function testCookiesReturnsEmptyArrayWhenNoSetCookieHeader(): void
    {
        $res = $this->make(200, '{}');
        $this->assertSame([], $res->cookies());
    }
}
