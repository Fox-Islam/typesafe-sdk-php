<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

use Throwable;

/**
 * An attempt exceeded its timeout. A kind of {@see ConnectionException}, since
 * nothing usable came back.
 */
final class TimeoutException extends ConnectionException
{
    public function __construct(
        private readonly float $timeout,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Request timed out after %ss.', rtrim(rtrim(number_format($timeout, 3, '.', ''), '0'), '.')), $previous);
    }

    /** The timeout that elapsed, in seconds. */
    public function getTimeout(): float
    {
        return $this->timeout;
    }
}
