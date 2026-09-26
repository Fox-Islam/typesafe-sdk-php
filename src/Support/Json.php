<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Support;

use JsonException;
use Phox\TypeSafe\Exceptions\TypeSafeException;

final class Json
{
    /**
     * A float keeps its `.0`: PHP writes `1.0` as `1` without it, and Jev reads the two
     * differently. Asked whether an odd number of (1, 1, 0.96) is above 0.5, it answered 0.50 to
     * 0.58 with the ones sent as `1` and 0.55 to 0.66 as `1.0`.
     */
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private function __construct() {}

    /**
     * @throws TypeSafeException The value cannot be represented as JSON.
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode($value, self::FLAGS);
        } catch (JsonException $exception) {
            throw new TypeSafeException("Request body could not be encoded as JSON: {$exception->getMessage()}", previous: $exception);
        }
    }

    /**
     * Decode a response body, returning the raw text when it is not JSON and `null` when it is empty.
     *
     * Content types are not trusted: servers and proxies do not always set them.
     */
    public static function decode(string $body): mixed
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $body;
        }
    }
}
