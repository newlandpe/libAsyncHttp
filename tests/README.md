# Tests

```bash
composer install
composer test
```

## Layout

- `tests/Server/` — pure-logic unit tests: `Router`/`CompiledRoute` matching,
  `MiddlewarePipeline` ordering and short-circuiting, `Request` parsing,
  `Response` building, `ClientConnection`'s read/write state machine.
- `tests/Client/` — `Response` (client-side) helpers, and `HttpClient`
  wiring (see below).
- `tests/Integration/` — real TCP sockets, real `HttpServer`, driven by
  manually calling `poll()` in a loop instead of a real PocketMine scheduler
  tick. Covers fragmented reads, `Content-Length` edge cases, timeouts,
  disconnects, large responses (the fwrite partial-write fix), Keep-Alive
  across multiple requests on one connection, and concurrent clients.

## `tests/Stubs/`

PocketMine-MP isn't installed for this test suite (it's a large runtime
dependency that isn't worth pulling in just to unit test the parts of this
library that don't need a real server). `tests/Stubs/` contains a minimal,
purpose-built stand-in for exactly the PocketMine API surface this library
touches: `PluginBase`, `Server`/`AsyncPool`, `Task`/`AsyncTask`/
`TaskHandler`/`TaskScheduler`, and `Internet`/`InternetRequestResult`. There's
also a trimmed `SOFe\AwaitGenerator\Await::promise()` sufficient for
`HttpClient`'s usage.

These stubs are wired up only via `autoload-dev` in `composer.json`, so they
never ship as part of the library and never conflict with a real
`pocketmine/pocketmine-mp` install in a project that depends on this
library — Composer only loads a package's `autoload-dev` for the *root*
project, not for dependencies.

**What the stubs can and cannot prove:** the `AsyncPool` stub runs
`AsyncTask::onRun()`/`onCompletion()` synchronously in the same process,
so these tests cannot reproduce real multi-threaded (de)serialization.
What they do verify is the actual wiring: that `storeLocal()`/`fetchLocal()`
correctly round-trip the `resolve`/`reject` closures, that retries count
correctly, and that the whole `HttpServer` read/dispatch/write state
machine behaves correctly against real sockets. The guarantee that
`Closure`/`resource` properties can't survive a *real* thread boundary — the
original bug — comes from PocketMine's own `AsyncTask` contract, not from
anything these tests can exercise directly.
