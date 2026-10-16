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
 * System One endpoint. The answer bodies are the same on both, and so are the
 * request bodies apart from images - the provider decides the host, the path,
 * which key is read, which header carries the request id, and how images are
 * sent. Everything a caller writes stays identical, model names included:
 * OpenRouter accepts the bare `jev-latest` and resolves it to the same build
 * TypeSafe does.
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
            self::OpenRouter => TypeSafe::OPENROUTER_SYSTEM_ONE_PATH,
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
     * OpenRouter's System One endpoint has no catalogue of its own, and Jev is
     * not in the `/api/v1/models` listing that covers its chat models, so there
     * is nothing to call instead of something that returns an empty list.
     */
    public function listsModels(): bool
    {
        return $this === self::TypeSafe;
    }

    /**
     * Whether the provider takes images and box questions. Both providers do.
     *
     * @deprecated Always true. Use {@see inlinesImages()} for how images are sent.
     */
    public function acceptsImages(): bool
    {
        return true;
    }

    /**
     * Whether images are written into the JSON as `image_url` parts instead of
     * uploaded alongside it.
     *
     * OpenRouter rejects a multipart body (400 invalid content-type) and silently
     * drops an `assets` field. It reads an image only as a part carrying a base64
     * data URI, placed where the image goes.
     *
     * @see https://openrouter.ai/docs/guides/community/multimodal-decisions
     */
    public function inlinesImages(): bool
    {
        return $this === self::OpenRouter;
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
