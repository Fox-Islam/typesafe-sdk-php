<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Retry;

use Phox\TypeSafe\Support\Headers;
use Phox\TypeSafe\TypeSafe;

/**
 * Reads the delay a server asked for out of its response headers.
 */
final class RetryAfter
{
    private function __construct() {}

    /**
     * Parse `retry-after-ms` or `Retry-After` into seconds, preferring the
     * millisecond header. Returns `null` when neither carries a usable delay.
     *
     * @param array<string, string> $headers
     */
    public static function parse(array $headers, ?int $now = null): ?float
    {
        $milliseconds = Headers::get($headers, TypeSafe::RETRY_AFTER_MS_HEADER);
        if ($milliseconds !== null && is_numeric($milliseconds) && (float) $milliseconds >= 0) {
            return (float) $milliseconds / 1000;
        }

        $retryAfter = Headers::get($headers, TypeSafe::RETRY_AFTER_HEADER);
        if ($retryAfter === null) {
            return null;
        }

        if (is_numeric($retryAfter)) {
            return (float) $retryAfter >= 0 ? (float) $retryAfter : null;
        }

        $date = strtotime($retryAfter);
        if ($date === false) {
            return null;
        }

        return max(0.0, (float) ($date - ($now ?? time())));
    }
}
