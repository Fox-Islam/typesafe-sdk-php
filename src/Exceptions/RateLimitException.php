<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

use Phox\TypeSafe\Retry\RetryAfter;

/** HTTP 429: the account's rate limit was exceeded. */
final class RateLimitException extends ApiException
{
    /**
     * The delay the server asked for, in seconds, from `retry-after-ms` or
     * `Retry-After`, or `null` when neither header carried a usable value.
     */
    public function getRetryAfter(): ?float
    {
        return RetryAfter::parse($this->getHeaders());
    }
}
