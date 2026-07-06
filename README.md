# libAsyncHttp Virion

[![Poggit CI](https://poggit.pmmp.io/ci.shield/newlandpe/libAsyncHttp/libAsyncHttp)](https://poggit.pmmp.io/ci/newlandpe/libAsyncHttp/libAsyncHttp)

`libAsyncHttp` is a modern, asynchronous HTTP client and a lightweight HTTP server library designed specifically for PocketMine-MP plugins. It enables seamless web integration, allowing your plugins to interact with external APIs and expose internal HTTP endpoints without blocking the main server thread.

## Features

- **Full Asynchronicity**: Leverages `SOFe\AwaitGenerator` for non-blocking HTTP requests and server operations.
- **HTTP Client**: Easily make GET, POST, PUT, DELETE requests to external APIs.
- **Lightweight HTTP Server**: Create simple HTTP endpoints within your plugin.
- **Routing System**: Define routes for different HTTP methods and paths.
- **Middleware Support**: Implement custom middleware for request processing (e.g., authentication, CORS).
- **Type-Safe**: Strict types and custom exceptions for robust development.
- **PocketMine-MP Integration**: Designed for full compatibility with the PocketMine-MP environment.

## Installation

To use `libAsyncHttp` in your PocketMine-MP plugin, you must integrate it using a build tool that supports **Virion injection**. This ensures proper namespace isolation and dependency management.

1. **Use a Virion Injector/Builder (Recommended)**:
   PocketMine-MP plugins rely on specialized build tools (like **DevTools** or a custom **Phar builder**) to handle virion injection.
   - Ensure your build script is configured to recognize and inject `libAsyncHttp`. The virion's classes will then be correctly namespaced under its **antigen** (`ChernegaSergiy\AsyncHttp`) within your plugin's final PHAR file.
   - **This is the standard and recommended way** to manage Virion dependencies to prevent conflicts.

2. **Local Development Setup (Optional)**:
   For a smoother local development workflow (e.g., to enable IDE autocompletion) before running the build tool, you may manually configure the class autoloader.
   - If you have the virion files in a local directory (e.g., `virions/libAsyncHttp/`), you can add a path mapping to your plugin's `composer.json` **`autoload`** section:
   ```json
   // In your plugin's composer.json
   {
       "autoload": {
           "psr-4": {
               "YourPluginNamespace\\": "src/",
               // Add this line for local testing:
               "ChernegaSergiy\\AsyncHttp\\": "virions/libAsyncHttp/src/ChernegaSergiy/AsyncHttp/"
           }
       }
   }
   ```
   - Then run `composer dump-autoload` (or `composer update`) locally. Remember, this step only helps local development; the **Virion Builder** handles the final injection into the PHAR.

## Usage Examples

### Initializing the Library

In your main plugin class (`onEnable` method):

```php
<?php

namespace YourPlugin;

use pocketmine\plugin\PluginBase;
use ChernegaSergiy\AsyncHttp\libAsyncHttp;
use ChernegaSergiy\AsyncHttp\Server\Middleware\AuthMiddleware;
use SOFe\AwaitGenerator\Await;

class Main extends PluginBase
{
    private libAsyncHttp $http;

    public function onEnable(): void
    {
        $this->http = libAsyncHttp::create($this);
        $this->getLogger()->info("libAsyncHttp initialized.");

        // ... (rest of your plugin logic)
    }

    public function onDisable(): void
    {
        if ($this->http->getServer()) {
            $this->http->getServer()->stop();
        }
    }
}
```

### Using the HTTP Client

Make asynchronous HTTP requests to external services:

```php
<?php
// ... inside your plugin class or a method that supports AwaitGenerator

use ChernegaSergiy\AsyncHttp\Client\HttpClient; // Corrected namespace
use SOFe\AwaitGenerator\Await;

// Example: Fetching user data from an external API
Await::f2c(function() use ($this) {
    try {
        $client = $this->http->getClient(); // Get the initialized client
        $client->setBaseUrl('https://jsonplaceholder.typicode.com'); // Set a base URL

        $response = yield from $client->get('/users/1');

        if ($response->isSuccess()) {
            $userData = $response->json();
            $this->getLogger()->info("Fetched user: " . $userData['name']);
        } else {
            $this->getLogger()->warning("Failed to fetch user: " . $response->getStatusCode() . " - " . $response->getBody());
        }
    } catch (\Exception $e) {
        $this->getLogger()->error("HTTP Client Error: " . $e->getMessage());
    }
});

// Example: Sending a POST request
Await::f2c(function() use ($this) {
    try {
        $client = $this->http->getClient();
        $client->setBaseUrl('https://jsonplaceholder.typicode.com');

        $newPost = [
            'title' => 'foo',
            'body' => 'bar',
            'userId' => 1,
        ];

        $response = yield from $client->post('/posts', $newPost);

        if ($response->isSuccess()) {
            $createdPost = $response->json();
            $this->getLogger()->info("Created new post with ID: " . $createdPost['id']);
        } else {
            $this->getLogger()->warning("Failed to create post: " . $response->getStatusCode() . " - " . $response->getBody());
        }
    } catch (\Exception $e) {
        $this->getLogger()->error("HTTP Client Error: " . $e->getMessage());
    }
});
```

### Setting up an HTTP Server

Expose an HTTP endpoint from your plugin:

```php
<?php
// ... inside your plugin class or a method that supports AwaitGenerator

use ChernegaSergiy\AsyncHttp\Server\HttpServer;
use ChernegaSergiy\AsyncHttp\Server\Request;
use ChernegaSergiy\AsyncHttp\Server\Response;
use ChernegaSergiy\AsyncHttp\Server\Middleware\AuthMiddleware;

// Example: Starting a simple server with a route and middleware
public function startWebServer(): void
{
    $server = $this->http->createServer('0.0.0.0', 8080);

    // Add authentication middleware
    $server->addMiddleware(new AuthMiddleware('your-secret-api-key'));

    // Define a GET route
    $server->getRouter()->get('/hello', function(Request $request, Response $response) {
        $name = $request->getQueryParam('name', 'World');
        $response->text("Hello, " . $name . "!");
    });

    // Define a POST route
    $server->getRouter()->post('/data', function(Request $request, Response $response) {
        $data = $request->json();
        $response->json(['received' => $data, 'status' => 'success']);
    });

    try {
        $server->start();
        $this->getLogger()->info("HTTP Server started on port 8080.");
    } catch (\Exception $e) {
        $this->getLogger()->error("Failed to start HTTP Server: " . $e->getMessage());
    }
}

// Call this method in your onEnable()
// $this->startWebServer();
```

## API Reference (Key Classes)

- **`ChernegaSergiy\AsyncHttp\libAsyncHttp`**: The main entry point for the library. Provides access to the HTTP client and allows creating HTTP server instances.
  - `static create(PluginBase $plugin)`: Initializes the library.
  - `getClient()`: Returns an `HttpClient` instance.
  - `createServer(string $host, int $port)`: Creates and returns an `HttpServer` instance.
- **`ChernegaSergiy\AsyncHttp\Client\HttpClient`**: For making outgoing HTTP requests.
  - `setBaseUrl(string $url)`: Sets a base URL for requests.
  - `setDefaultHeaders(array $headers)`: Sets headers to be sent with all requests.
  - `get(string $endpoint, array $headers)`: Sends a GET request.
  - `post(string $endpoint, $data, array $headers)`: Sends a POST request.
  - `put(string $endpoint, $data, array $headers)`: Sends a PUT request.
  - `delete(string $endpoint, array $headers)`: Sends a DELETE request.
- **`ChernegaSergiy\AsyncHttp\Client\Response`**: Represents a response from an HTTP client request.
  - `getStatusCode()`: Gets the HTTP status code.
  - `getBody()`: Gets the raw response body.
  - `json()`: Decodes the JSON response body into an array.
  - `isSuccess()`: Checks if the response status code indicates success (2xx).
- **`ChernegaSergiy\AsyncHttp\Server\HttpServer`**: For creating an internal HTTP server. Runs entirely on the main thread via a repeating scheduler `Task` (see "Architecture" below) — it never uses `AsyncTask`.
  - `getRouter()`: Returns the `Router` instance for defining routes.
  - `addMiddleware(MiddlewareInterface $middleware)`: Adds a middleware to the pipeline, in call order.
  - `start()`: Starts the HTTP server.
  - `stop()`: Stops the HTTP server and closes all open connections.
- **`ChernegaSergiy\AsyncHttp\Server\Router`**: Manages HTTP routes for the server. Supports `{param}` path segments.
  - `get(string $path, callable $handler)`: Defines a GET route.
  - `post(string $path, callable $handler)`: Defines a POST route.
  - `put(string $path, callable $handler)`: Defines a PUT route.
  - `patch(string $path, callable $handler)`: Defines a PATCH route.
  - `delete(string $path, callable $handler)`: Defines a DELETE route.
  - `any(string $path, callable $handler)`: Defines a route for all common HTTP methods.
  - Example: `$router->get('/users/{id}', fn(Request $r, Response $res) => $res->json(['id' => $r->getRouteParam('id')]));`
- **`ChernegaSergiy\AsyncHttp\Server\Request`**: Represents an incoming HTTP request to the server.
  - `getMethod()`: Gets the HTTP method.
  - `getPath()`: Gets the request path.
  - `getHeaders()`: Gets all request headers.
  - `getBody()`: Gets the raw request body.
  - `json()`: Decodes the JSON request body.
  - `getQueryParam(string $key, $default)`: Gets a query parameter.
- **`ChernegaSergiy\AsyncHttp\Server\Response`**: Represents an HTTP response from the server.
  - `setStatus(int $code)`: Sets the HTTP status code.
  - `setHeader(string $name, string $value)`: Sets a response header.
  - `json(array $data)`: Sends a JSON response.
  - `text(string $text)`: Sends a plain text response.
  - `html(string $html)`: Sends an HTML response.
  - `send(string $body, string $contentType)`: Sends a custom response.
- **`ChernegaSergiy\AsyncHttp\Server\Middleware\MiddlewareInterface`**: Interface for creating custom middleware.
- **`ChernegaSergiy\AsyncHttp\Server\Middleware\AuthMiddleware`**: Example middleware for API key authentication.
- **`ChernegaSergiy\AsyncHttp\Server\Middleware\CorsMiddleware`**: Example middleware for handling Cross-Origin Resource Sharing.

## Architecture

This matters if you're deciding whether to trust this library in production, so it's spelled out explicitly:

- **`HttpClient`** dispatches each request as a `pocketmine\scheduler\AsyncTask` (`HttpRequestTask`). Only thread-safe scalars/arrays (method, URL, headers, body, timeout) are ever stored as task properties. The `resolve`/`reject` closures from `Await::promise()` never cross the thread boundary — they're kept on the main thread via `AsyncTask::storeLocal()`/`fetchLocal()`, which is the mechanism PocketMine provides specifically for this.
- **`HttpServer`** does **not** use `AsyncTask` at all. An embedded HTTP listener is a long-lived accept/read/write loop, and `Router`, middleware, and the plugin logger all rely on closures and objects that can't be handed to a worker thread — and even if they could, `AsyncTask::onRun()` is meant to finish and report a single result, not run forever. Instead, `HttpServer` schedules a normal repeating `Task` (`ServerPollTask`) that runs on the main thread once per tick. Every socket call inside it (`accept`, `select`, `read`) uses a zero-second timeout, so a poll costs microseconds; requests are assembled incrementally across ticks by a small per-connection state machine (`ClientConnection`) that tracks `Content-Length` properly instead of doing a single fixed-size `fread()`.
- **Middleware** implements `MiddlewareInterface::process(Request $request, Response $response, callable $next)`. Call `$next($request, $response)` to continue the chain (`Auth -> Cors -> ... -> Router::handle`); don't call it to short-circuit (e.g. after writing a 401). Note: this is onion-style — code *after* the `$next()` call in an outer middleware still runs on unwind even if an inner middleware short-circuits; only the downstream call chain is skipped.
- **Routing** supports `{param}` path segments, compiled once per route into a regex (`CompiledRoute`), so `/users/{id}` matches `/users/15` and populates `$request->getRouteParam('id')`.
- **Writes and Keep-Alive**: responses are written through a `writeBuffer`/`writeOffset` pair on `ClientConnection` (see the READ -> DISPATCH -> WRITE phases in `HttpServer`'s docblock) instead of a single `fwrite()` call, so a large response isn't silently truncated if a non-blocking socket accepts fewer bytes than requested. Once a response is fully flushed, the connection either closes (`Connection: close`) or resets and goes back to reading the next request on the same socket (`Connection: keep-alive`, the HTTP/1.1 default), bounded by a `maxKeepAliveRequests` cap per connection.

Known limitations that are still open (contributions welcome): no `Transfer-Encoding: chunked` support for request/response bodies, and the client's retry logic is a simple fixed-attempt loop rather than exponential backoff.

## Testing

```bash
composer install
composer test
```

63 tests covering routing (including `{param}` matching), the middleware
pipeline, request/response parsing, the `HttpClient` thread-safety wiring,
and real-socket integration tests for `HttpServer` (fragmented reads,
`Content-Length` edge cases, timeouts, disconnects, large responses,
Keep-Alive, and concurrent clients). See `tests/README.md` for details on
how PocketMine dependencies are stubbed for testing.

## Contributing

Contributions are welcome and appreciated! Here's how you can contribute:

1. Fork the project
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

Please make sure to update tests as appropriate and adhere to the existing coding style.

## License

This library is licensed under the CSSM Unlimited License v2.0 (CSSM-ULv2). Please note that this is a custom license. See the [LICENSE](LICENSE) file for details.
