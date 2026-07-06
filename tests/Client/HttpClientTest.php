<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Tests\Client;

use ChernegaSergiy\AsyncHttp\Client\HttpClient;
use ChernegaSergiy\AsyncHttp\Client\Response;
use ChernegaSergiy\AsyncHttp\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetRequestResult;

/**
 * These tests exercise the exact wiring that used to be broken: a Closure
 * (resolve/reject) created on the "main thread" side of Await::promise(),
 * carried through an AsyncTask (HttpRequestTask), and invoked back on
 * completion. The AsyncPool stub runs onRun()/onCompletion() synchronously
 * in-process rather than on a real worker thread — it can't reproduce real
 * thread serialization, but it does prove the resolve/reject callbacks
 * survive the storeLocal()/fetchLocal() round trip and get called with the
 * right value/exception, which is the part that was previously impossible
 * because the callbacks were stored as plain (non-thread-safe) properties.
 */
final class HttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Internet::$mock = null;
    }

    /**
     * @return mixed
     */
    private function drain(\Generator $gen)
    {
        foreach ($gen as $_) {
            // fully drain; Await::promise resolves synchronously against
            // the synchronous AsyncPool stub, so this loop body never runs
        }
        return $gen->getReturn();
    }

    public function testSuccessfulRequestResolvesWithResponse(): void
    {
        Internet::$mock = function (string $url, int $timeout, array $headers, array $opts) {
            $this->assertSame('https://api.example.com/oauth/token', $url);
            $this->assertSame('POST', $opts[CURLOPT_CUSTOMREQUEST]);
            return new InternetRequestResult(['content-type' => 'application/json'], '{"access_token":"tok"}', 200);
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com');
        $response = $this->drain($client->post('/oauth/token', ['grant_type' => 'client_credentials']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->status());
        $this->assertSame(['access_token' => 'tok'], $response->json());
    }

    public function testJsonBodyIsEncodedWithContentTypeHeader(): void
    {
        $capturedBody = null;
        $capturedHeaders = null;

        Internet::$mock = function (string $url, int $timeout, array $headers, array $opts) use (&$capturedBody, &$capturedHeaders) {
            $capturedBody = $opts[CURLOPT_POSTFIELDS];
            $capturedHeaders = $headers;
            return new InternetRequestResult([], '{}', 200);
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com');
        $this->drain($client->post('/x', ['a' => 1]));

        $this->assertSame('{"a":1}', $capturedBody);
        $this->assertSame('application/json', $capturedHeaders['Content-Type']);
    }

    public function testConnectionFailureRejectsWithConnectionException(): void
    {
        Internet::$mock = function () {
            throw new \RuntimeException('DNS resolution failed');
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com');

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('DNS resolution failed');
        $this->drain($client->get('/x'));
    }

    public function testRetriesUpToMaxRetriesBeforeFailing(): void
    {
        $attempts = 0;
        Internet::$mock = function () use (&$attempts) {
            $attempts++;
            throw new \RuntimeException('timeout');
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com', [], 10, 2);

        try {
            $this->drain($client->get('/x'));
            $this->fail('expected ConnectionException');
        } catch (ConnectionException $e) {
            // expected
        }

        $this->assertSame(3, $attempts, '1 initial attempt + 2 retries');
    }

    public function testSucceedsOnRetryAfterInitialFailure(): void
    {
        $attempts = 0;
        Internet::$mock = function () use (&$attempts) {
            $attempts++;
            if ($attempts < 2) {
                throw new \RuntimeException('flaky');
            }
            return new InternetRequestResult([], '{"ok":true}', 200);
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com', [], 10, 3);
        $response = $this->drain($client->get('/x'));

        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame(2, $attempts);
    }

    public function testDefaultHeadersAreMergedWithPerRequestHeaders(): void
    {
        $capturedHeaders = null;
        Internet::$mock = function (string $url, int $timeout, array $headers) use (&$capturedHeaders) {
            $capturedHeaders = $headers;
            return new InternetRequestResult([], '{}', 200);
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com', ['Authorization' => 'Bearer default']);
        $this->drain($client->get('/x', ['X-Trace' => 'abc']));

        $this->assertSame('Bearer default', $capturedHeaders['Authorization']);
        $this->assertSame('abc', $capturedHeaders['X-Trace']);
    }

    public function testRequestBuilderDelegatesToClient(): void
    {
        Internet::$mock = function (string $url, int $timeout, array $headers, array $opts) {
            return new InternetRequestResult([], json_encode(['seen' => $opts[CURLOPT_CUSTOMREQUEST]]), 200);
        };

        $client = new HttpClient(new PluginBase(), 'https://api.example.com');
        $response = $this->drain(
            $client->builder('GET', '/x')->header('X-Foo', 'bar')->put()
        );

        $this->assertSame(['seen' => 'PUT'], $response->json());
    }
}
