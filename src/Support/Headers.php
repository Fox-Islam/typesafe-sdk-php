<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Support;

/**
 * Case-insensitive header merging and redaction of credentials before logging.
 */
final class Headers
{
    /** Credential headers that keep a short suffix so a key can still be identified. */
    private const array KEY_HEADERS = ['authorization', 'proxy-authorization', 'x-api-key', 'api-key'];

    /** Headers whose values are replaced in full. */
    private const array OPAQUE_HEADERS = ['cookie', 'set-cookie'];

    private function __construct() {}

    /**
     * Merge header sets, with later values winning regardless of casing.
     *
     * A `null` value removes a header that an earlier set supplied.
     *
     * @param array<string, string|null> ...$sets
     * @return array<string, string>
     */
    public static function merge(array ...$sets): array
    {
        /** @var array<string, array{string, string}> $merged */
        $merged = [];

        foreach ($sets as $set) {
            foreach ($set as $name => $value) {
                $key = strtolower($name);

                if ($value === null) {
                    unset($merged[$key]);
                    continue;
                }

                $merged[$key] = [$name, $value];
            }
        }

        $headers = [];
        foreach ($merged as [$name, $value]) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Normalise a PSR-7 header map, joining repeated values the way HTTP does.
     *
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    public static function flatten(array $headers): array
    {
        $flattened = [];
        foreach ($headers as $name => $values) {
            $flattened[$name] = implode(', ', $values);
        }

        return $flattened;
    }

    /**
     * Look a header up without caring about the casing the server used.
     *
     * @param array<string, string> $headers
     */
    public static function get(array $headers, string $name): ?string
    {
        $name = strtolower($name);

        foreach ($headers as $header => $value) {
            if (strtolower($header) === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Copy headers with known credential values masked, for logging.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function redact(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $key = strtolower($name);

            $redacted[$name] = match (true) {
                in_array($key, self::KEY_HEADERS, true) => self::mask($value),
                in_array($key, self::OPAQUE_HEADERS, true) => '***',
                default => $value,
            };
        }

        return $redacted;
    }

    /**
     * Keep the scheme and the last four characters of a secret longer than eight.
     */
    private static function mask(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 2) ?: [$value];
        $scheme = count($parts) === 2 ? $parts[0] : null;
        $secret = $parts[count($parts) === 2 ? 1 : 0];

        $tail = strlen($secret) > 8 ? substr($secret, -4) : '';

        return ($scheme === null ? '' : "{$scheme} ") . "***{$tail}";
    }
}
