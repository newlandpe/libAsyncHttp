<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Server;

use ChernegaSergiy\AsyncHttp\Server\ClientConnection;
use ChernegaSergiy\AsyncHttp\Server\Request;
use PHPUnit\Framework\TestCase;

final class ClientConnectionTest extends TestCase
{
    /** @return resource */
    private function fakeSocket()
    {
        return fopen('php://memory', 'r+');
    }

    public function testNotCompleteBeforeHeadersArrive(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $conn->readBuffer = "POST /x HTTP/1.1\r\nHost: a";
        $this->assertFalse($conn->isRequestComplete());
    }

    public function testNotCompleteWhileBodyStillArriving(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $headerBlob = "POST /x HTTP/1.1\r\nContent-Length: 10";
        [$conn->method, $conn->path, $conn->protocol, $conn->headers] = Request::parseHeaderBlob($headerBlob);
        $conn->headersComplete = true;
        $conn->contentLength = 10;
        $conn->readBuffer = $headerBlob . "\r\n\r\n" . 'partial'; // only 7 of 10 bytes
        $conn->headerEnd = strlen($headerBlob) + 4;

        $this->assertFalse($conn->isRequestComplete());
    }

    public function testCompleteOnceFullBodyArrives(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $headerBlob = "POST /x HTTP/1.1\r\nContent-Length: 5";
        $conn->headersComplete = true;
        $conn->contentLength = 5;
        $conn->headerEnd = strlen($headerBlob) + 4;
        $conn->readBuffer = $headerBlob . "\r\n\r\n" . 'hello';

        $this->assertTrue($conn->isRequestComplete());
        $this->assertSame('hello', $conn->getBody());
    }

    public function testZeroContentLengthCompletesImmediatelyAfterHeaders(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $headerBlob = "GET /x HTTP/1.1\r\nHost: a";
        $conn->headersComplete = true;
        $conn->contentLength = 0;
        $conn->headerEnd = strlen($headerBlob) + 4;
        $conn->readBuffer = $headerBlob . "\r\n\r\n";

        $this->assertTrue($conn->isRequestComplete());
        $this->assertSame('', $conn->getBody());
    }

    public function testBeginWriteSwitchesStateAndTracksKeepAlive(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $conn->beginWrite('HTTP/1.1 200 OK...', true);

        $this->assertSame(ClientConnection::STATE_WRITING, $conn->state);
        $this->assertTrue($conn->hasPendingWrite());
        $this->assertTrue($conn->keepAliveAfterWrite);
    }

    public function testHasPendingWriteBecomesFalseOnceOffsetReachesBufferLength(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $conn->beginWrite('12345', false);
        $conn->writeOffset = 5;

        $this->assertFalse($conn->hasPendingWrite());
    }

    public function testResetForNextRequestClearsReadAndWriteStateButKeepsCounters(): void
    {
        $conn = new ClientConnection($this->fakeSocket());
        $conn->readBuffer = 'garbage';
        $conn->headersComplete = true;
        $conn->contentLength = 99;
        $conn->beginWrite('response bytes', true);
        $conn->writeOffset = 14;

        $conn->resetForNextRequest();

        $this->assertSame('', $conn->readBuffer);
        $this->assertFalse($conn->headersComplete);
        $this->assertSame(0, $conn->contentLength);
        $this->assertSame('', $conn->writeBuffer);
        $this->assertSame(0, $conn->writeOffset);
        $this->assertSame(ClientConnection::STATE_READING, $conn->state);
        $this->assertSame(1, $conn->requestsHandled, 'each reset should count as one completed request');
    }
}
