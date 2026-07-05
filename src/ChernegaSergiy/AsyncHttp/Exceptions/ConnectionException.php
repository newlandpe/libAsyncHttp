<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Exceptions;

/**
 * Thrown when the underlying transport (curl / socket) fails to establish
 * or complete a connection. Distinct from RequestException so callers can
 * tell "we never got a response" apart from "server sent an error".
 */
class ConnectionException extends RequestException
{
}
