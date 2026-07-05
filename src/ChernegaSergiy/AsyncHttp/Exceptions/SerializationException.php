<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Exceptions;

/**
 * Thrown when data that must cross a thread boundary (into an AsyncTask)
 * cannot be safely represented as thread-safe scalars/arrays. This is a
 * defensive guard: libAsyncHttp never lets Closures, resources or objects
 * containing them reach a worker thread in the first place, but this
 * exception exists so misuse fails loudly instead of crashing the worker.
 */
class SerializationException extends HttpException
{
}
