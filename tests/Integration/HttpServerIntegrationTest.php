<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Integration;

use ChernegaSergiy\AsyncHttp\Server\HttpServer;
use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;
use PHPUnit\Framework\TestCase;
use pocketmine\plugin\PluginBase;

/**
 * Real TCP sockets, real HttpServer, real poll() ticks — no PocketMine
 * process is involved, but nothing about the socket handling or the
 * READ -> DISPATCH -> WRITE state machine is mocked.
 *
 * The server is driven by manually calling ->poll() in a loop instead of
 * going through a real scheduler tick, since these tests need precise
 * control over timing (partial writes, fragmented reads, timeouts). Every
 * helper here that waits for a response also keeps calling ->poll(), since
 * nothing else advances the server between calls (there is no background
 * thread — that's the whole point of the architecture).
 */
final class HttpServerIntegrationTest extends TestCase
{
    private static int $portCounter = 18100;

    /** @var HttpServer[] */
    private array $serversToStop = [];

    private function startServer(int $timeoutSeconds = 2): array
    {
        $port = self::$portCounter++;
        $server = new HttpServer(new PluginBase(), '127.0.0.1', $port, $timeoutSeconds);
        $server->start();
        $this->serversToStop[] = $server;
        return [$server, $port];
    }

    protected function tearDown(): void
    {
        foreach ($this->serversToStop as $server) {
            $server->stop();
        }
        $this->serversToStop = [];
    }

    /**
     * Polls the server for a fixed wall-clock duration, regardless of
     * outcome. Used when we don't have a cheap way to peek "is the client
     * socket about to be closed" etc.
     */
    private function pumpFor(HttpServer $server, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $server->poll();
            usleep(2000);
        }
    }

    /**
     * Polls the server while reading from $socket until either:
     *  - a full HTTP message (headers + Content-Length bytes) is received, or
     *  - the socket hits EOF, or
     *  - $maxSeconds elapses.
     *
     * Returns whatever was read (may be a partial/empty string on timeout).
     */
    private function receiveResponse(HttpServer $server, $socket, float $maxSeconds = 3.0): string
    {
        stream_set_blocking($socket, false);
        $buffer = '';
        $headerEnd = false;
        $contentLength = 0;
        $deadline = microtime(true) + $maxSeconds;

        while (microtime(true) < $deadline) {
            $server->poll();

            $chunk = @fread($socket, 65536);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
            } elseif ($chunk === '' && feof($socket) && $buffer !== '') {
                break;
            }

            if ($headerEnd === false) {
                $pos = strpos($buffer, "\r\n\r\n");
                if ($pos !== false) {
                    $headerEnd = $pos + 4;
                    if (preg_match('/Content-Length:\s*(\d+)/i', substr($buffer, 0, $pos), $m)) {
                        $contentLength = (int) $m[1];
                    }
                }
            }

            if ($headerEnd !== false && strlen($buffer) - $headerEnd >= $contentLength) {
                return $buffer;
            }

            usleep(2000);
        }

        return $buffer;
    }

    /**
     * Like receiveResponse(), but stops right after a full message instead
     * of waiting for EOF — needed for Keep-Alive, where the socket stays
     * open for a second request.
     */
    private function receiveOneMessage(HttpServer $server, $socket, float $maxSeconds = 3.0): string
    {
        return $this->receiveResponse($server, $socket, $maxSeconds);
    }

    public function testFragmentedHeadersAndBodyAcrossManyWrites(): void
    {
        [$server, $port] = $this->startServer();
        $server->getRouter()->post('/echo', function (Request $req, Response $res) {
            $res->json(['echo' => $req->json()]);
        });

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        $body = json_encode(['value' => str_repeat('a', 500)]);
        $raw = "POST /echo HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;

        // Split into ~100 tiny fragments, exactly the scenario asked for.
        $chunks = str_split($raw, (int) max(1, strlen($raw) / 100));
        foreach ($chunks as $chunk) {
            fwrite($client, $chunk);
            $server->poll();
        }

        $response = $this->receiveResponse($server, $client);
        [, $respBody] = explode("\r\n\r\n", $response, 2);
        $decoded = json_decode($respBody, true);

        $this->assertSame(str_repeat('a', 500), $decoded['echo']['value']);
    }

    public function testContentLengthZeroCompletesImmediately(): void
    {
        [$server, $port] = $this->startServer();
        $server->getRouter()->get('/ping', fn(Request $r, Response $res) => $res->json(['pong' => true]));

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        fwrite($client, "GET /ping HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        $response = $this->receiveResponse($server, $client);
        $this->assertStringStartsWith('HTTP/1.1 200', $response);
    }

    public function testInvalidRequestLineStillGetsAResponseNotACrash(): void
    {
        [$server, $port] = $this->startServer();

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        // No valid method/path structure, but a headers terminator is
        // present so the server will still try to parse and dispatch it —
        // it must not throw an uncaught exception either way.
        fwrite($client, "GARBAGE\r\nContent-Length: 0\r\n\r\n");

        $response = $this->receiveResponse($server, $client);
        $this->assertNotSame('', $response, 'server must respond (404/500/etc), not hang or crash silently');
    }

    public function testConnectionTimeoutRespondsWith408(): void
    {
        [$server, $port] = $this->startServer(1); // 1 second timeout

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        fwrite($client, "GET /never-finishes HTTP/1.1\r\nHost: x\r\nContent-Length: 100\r\n\r\n"); // headers only, body never sent

        $response = $this->receiveResponse($server, $client, 3.0);
        $this->assertStringStartsWith('HTTP/1.1 408', $response);
    }

    public function testClientDisconnectMidRequestIsHandledWithoutError(): void
    {
        [$server, $port] = $this->startServer();

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        fwrite($client, "POST /x HTTP/1.1\r\nHost: x\r\nContent-Length: 100\r\n\r\npartial-only");
        fclose($client); // disconnect before body is complete

        // Must not throw / must not hang; a few polls should just clean it up.
        $this->pumpFor($server, 0.3);

        $this->assertTrue(true, 'reaching this line means poll() did not throw on a disconnected socket');
    }

    public function testLargeResponseBodyIsFullyDeliveredDespitePartialNonBlockingWrites(): void
    {
        [$server, $port] = $this->startServer();

        // Large enough that a non-blocking fwrite() is very likely to
        // accept fewer bytes than requested in a single call — this is
        // exactly the bug that was fixed by tracking writeOffset instead
        // of assuming one fwrite() call sends everything.
        $bigValue = str_repeat('x', 400_000);
        $server->getRouter()->get('/big', function (Request $req, Response $res) use ($bigValue) {
            $res->json(['data' => $bigValue]);
        });

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        fwrite($client, "GET /big HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        $response = $this->receiveResponse($server, $client, 5.0);
        $this->assertNotSame('', $response);

        preg_match('/Content-Length: (\d+)/', $response, $m);
        $expectedLength = (int) $m[1];
        [, $respBody] = explode("\r\n\r\n", $response, 2);

        $this->assertSame($expectedLength, strlen($respBody), 'the full response body must be delivered, not truncated by a partial fwrite()');
        $decoded = json_decode($respBody, true);
        $this->assertSame($bigValue, $decoded['data'] ?? null);
    }

    public function testKeepAliveAllowsMultipleRequestsOnOneConnection(): void
    {
        [$server, $port] = $this->startServer();
        $counter = 0;
        $server->getRouter()->get('/count', function (Request $req, Response $res) use (&$counter) {
            $counter++;
            $res->json(['n' => $counter]);
        });

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");

        fwrite($client, "GET /count HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n");
        $first = $this->receiveOneMessage($server, $client);
        $this->assertStringContainsString('Connection: keep-alive', $first);
        [, $firstBody] = explode("\r\n\r\n", $first, 2);
        $this->assertSame(1, json_decode($firstBody, true)['n'] ?? null);

        // Second request on the SAME socket, without reconnecting.
        fwrite($client, "GET /count HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n");
        $second = $this->receiveOneMessage($server, $client);
        [, $secondBody] = explode("\r\n\r\n", $second, 2);
        $this->assertSame(2, json_decode($secondBody, true)['n'] ?? null, 'second request on the same TCP connection must be handled independently');
    }

    public function testConnectionCloseHeaderClosesSocketAfterOneRequest(): void
    {
        [$server, $port] = $this->startServer();
        $server->getRouter()->get('/x', fn(Request $r, Response $res) => $res->json(['ok' => true]));

        $client = stream_socket_client("tcp://127.0.0.1:{$port}");
        fwrite($client, "GET /x HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        $response = $this->receiveResponse($server, $client, 2.0);
        $this->assertStringContainsString('Connection: close', $response);

        $eof = false;
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $server->poll();
            if (@fread($client, 1) === '' && feof($client)) {
                $eof = true;
                break;
            }
            usleep(2000);
        }
        $this->assertTrue($eof, 'server must close the socket when Connection: close was requested');
    }

    public function testConcurrentClientsAreHandledIndependently(): void
    {
        [$server, $port] = $this->startServer();
        $server->getRouter()->get('/who', function (Request $req, Response $res) {
            $res->json(['name' => $req->getQueryParam('name')]);
        });

        $clients = [];
        foreach (['alice', 'bob', 'carol', 'dave', 'erin'] as $name) {
            $c = stream_socket_client("tcp://127.0.0.1:{$port}");
            stream_set_blocking($c, false);
            fwrite($c, "GET /who?name={$name} HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
            $clients[$name] = $c;
        }

        $responses = [];
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline && count($responses) < count($clients)) {
            $server->poll();
            foreach ($clients as $name => $c) {
                if (isset($responses[$name])) {
                    continue;
                }
                $chunk = @fread($c, 65536);
                if ($chunk !== false && $chunk !== '') {
                    $responses[$name] = ($responses[$name] ?? '') . $chunk;
                }
                if (isset($responses[$name]) && str_contains($responses[$name], "\r\n\r\n")) {
                    // has at least headers; good enough given small fixed-size bodies
                }
            }
            usleep(2000);
        }

        // Give any still-in-flight writes one more short window.
        $this->pumpFor($server, 0.2);
        foreach ($clients as $name => $c) {
            $more = @fread($c, 65536);
            if ($more) {
                $responses[$name] = ($responses[$name] ?? '') . $more;
            }
        }

        foreach ($clients as $name => $c) {
            $this->assertArrayHasKey($name, $responses, "no response received for {$name}");
            $parts = explode("\r\n\r\n", $responses[$name], 2);
            $this->assertCount(2, $parts, "response for {$name} did not contain a full header/body split: " . var_export($responses[$name], true));
            $this->assertSame($name, json_decode($parts[1], true)['name'] ?? null, "response for {$name} must not be mixed up with another client's");
        }
    }
}
