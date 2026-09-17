<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Http;

use Phox\TypeSafe\Retry\RetryPolicy;

/**
 * Per-request overrides. Anything left `null` falls back to the client setting.
 *
 * @internal
 */
final class RequestOptions
{
    /**
     * @param array<string, string> $headers Merged over the client's default headers.
     */
    public function __construct(
        public readonly ?float $timeout = null,
        public readonly array $headers = [],
        public readonly ?RetryPolicy $retry = null,
    ) {}
}
