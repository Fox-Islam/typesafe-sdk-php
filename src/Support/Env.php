<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Support;

/**
 * Environment variable names read when a setting is not supplied in code,
 * and the reader that ignores blank values.
 */
final class Env
{
    /** Required API key; used when `apiKey` is omitted. */
    public const string API_KEY = 'TYPESAFE_API_KEY';

    /** OpenRouter key; used instead of {@see API_KEY} when the provider is `openrouter`. */
    public const string OPENROUTER_API_KEY = 'OPENROUTER_API_KEY';

    /** `typesafe` or `openrouter`; defaults to `typesafe`. */
    public const string PROVIDER = 'TYPESAFE_PROVIDER';

    /** API root; defaults to the provider's own host. */
    public const string BASE_URL = 'TYPESAFE_BASE_URL';

    /** Default model name; defaults to `jev-latest`. */
    public const string DEFAULT_MODEL = 'TYPESAFE_DEFAULT_MODEL';

    /** Log level; defaults to `warn`. */
    public const string LOG_LEVEL = 'TYPESAFE_LOG_LEVEL';

    private function __construct() {}

    /**
     * Read a trimmed environment value, treating missing and blank values alike.
     */
    public static function read(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Return the value supplied in code, falling back to the environment, then the default.
     */
    public static function fallback(?string $value, string $name, ?string $default = null): ?string
    {
        return $value ?? self::read($name) ?? $default;
    }
}
