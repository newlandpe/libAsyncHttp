<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Client;

use ChernegaSergiy\AsyncHttp\Exceptions\ConnectionException;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Internet;
use Throwable;

/**
 * Runs a single HTTP request on a worker thread.
 *
 * CRITICAL: every property of this class is written to on the main thread
 * (in the constructor) and read on a worker thread (in onRun()). Because of
 * that, every property here MUST be a thread-safe scalar/array — strings,
 * ints, bools, or arrays of the same. Never add a Closure, resource, or an
 * object that (transitively) holds one, or the task will fail to be handed
 * off to the worker.
 *
 * The resolve/reject callbacks are Closures, so they are NEVER stored as
 * properties. Instead they travel via AsyncTask::storeLocal(), which keeps
 * them on the main thread and hands them back only in onCompletion()
 * (which always runs on the main thread too).
 */
final class HttpRequestTask extends AsyncTask
{
    private string $method;
    private string $url;
    private array $headers;
    private ?string $body;
    private int $timeout;
    private int $maxRetries;

    public function __construct(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxRetries = 0)
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
    }

    public function onRun(): void
    {
        $attempt = 0;
        $lastError = 'Unknown error';

        do {
            try {
                $result = Internet::simpleCurl($this->url, $this->timeout, $this->headers, [
                    CURLOPT_CUSTOMREQUEST => $this->method,
                    CURLOPT_POSTFIELDS => $this->body,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                ]);
                $this->setResult(['success' => true, 'result' => $result]);
                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $attempt++;
            }
        } while ($attempt <= $this->maxRetries);

        $this->setResult(['success' => false, 'error' => $lastError]);
    }

    public function onCompletion(): void
    {
        /** @var array{resolve: \Closure, reject: \Closure}|null $callbacks */
        $callbacks = $this->fetchLocal();
        if ($callbacks === null) {
            // Nothing we can do without the callbacks; avoid a fatal error.
            return;
        }

        ['resolve' => $resolve, 'reject' => $reject] = $callbacks;

        $result = $this->getResult();
        if (!is_array($result)) {
            $reject(new ConnectionException('Async HTTP task produced no result'));
            return;
        }

        if ($result['success']) {
            $resolve(new Response($result['result']));
        } else {
            $reject(new ConnectionException((string) ($result['error'] ?? 'Unknown HTTP client error')));
        }
    }
}
