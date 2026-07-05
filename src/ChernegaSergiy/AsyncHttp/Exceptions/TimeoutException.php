<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Exceptions;

/**
 * Thrown when a client request or a server-side connection exceeds its
 * configured timeout.
 */
class TimeoutException extends RequestException
{
}
