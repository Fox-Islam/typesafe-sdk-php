<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

use Throwable;

/**
 * The request never produced a complete response: DNS, TLS, a dropped
 * connection, or a truncated body.
 */
class ConnectionException extends TypeSafeException
{
    public function __construct(string $message = 'Connection error.', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
