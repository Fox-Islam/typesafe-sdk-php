<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Enums;

use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Support\Env;
use Phox\TypeSafe\TypeSafe;

/**
 * Where System One calls are sent.
 *
 * Jev is reachable two ways: directly from TypeSafe, and through OpenRouter's
 * decisions endpoint. The request and answer bodies are the same on both — the
 * provider only decides the host, the path, which key is read, and which header
 * carries the request id. Everything a caller writes stays identical, model names
 * included: OpenRouter accepts the bare `jev-latest` and resolves it to the same
 * build TypeSafe does.
 *
 * ```php
 * Client::make()->openRouter()->systemOne()->state($ticket)->noul('urgent')->send();
 * ```
 */
enum Provider: string
{
    /** `api.typesafe.ai`, keyed by `TYPESAFE_API_KEY`. */
    case TypeSafe = 'typesafe';

    /** `openrouter.ai`, keyed by `OPENROUTER_API_KEY`. */
    case OpenRouter = 'openrouter';

    public function baseUrl(): string
    {
        return match ($this) {
            self::TypeSafe => TypeSafe::DEFAULT_BASE_URL,
            self::OpenRouter => TypeSafe::OPENROUTER_BASE_URL,
        };
    }

    public function systemOnePath(): string
    {
        return match ($this) {
            self::TypeSafe => TypeSafe::SYSTEM_ONE_PATH,
            self::OpenRouter => TypeSafe::OPENROUTER_DECISIONS_PATH,
        };
    }

    /** The environment variable read when no key is passed in code. */
    public function apiKeyEnv(): string
    {
        return match ($this) {
            self::TypeSafe => Env::API_KEY,
            self::OpenRouter => Env::OPENROUTER_API_KEY,
        };
    }

    /** The response header carrying the id to quote in a bug report. */
    public function requestIdHeader(): string
    {
        return match ($this) {
            self::TypeSafe => TypeSafe::REQUEST_ID_HEADER,
            self::OpenRouter => TypeSafe::GENERATION_ID_HEADER,
        };
    }

    /**
     * Whether the provider can list models.
     *
     * OpenRouter's decisions endpoint has no catalogue of its own, and Jev is
     * not in the `/api/v1/models` listing that covers its chat models, so there
     * is nothing to call rather than something that returns an empty list.
     */
    public function listsModels(): bool
    {
        return $this === self::TypeSafe;
    }

    /**
     * @throws TypeSafeException The value does not name a provider.
     */
    public static function parse(string $value, string $source): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? throw new TypeSafeException(sprintf(
            'Unknown provider "%s" in %s; expected one of: %s.',
            $value,
            $source,
            implode(', ', array_column(self::cases(), 'value')),
        ));
    }
}
