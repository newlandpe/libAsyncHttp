<?php

declare(strict_types=1);

namespace ChernegaSergiy\AsyncHttp\Exceptions;

/**
 * Thrown when a response cannot be parsed as expected, e.g. json() is
 * called on a body that is not valid JSON.
 */
class InvalidResponseException extends RequestException
{
}
