<?php

declare(strict_types=1);

namespace Phox\TypeSafe;

/**
 * SDK-wide constants: version, protocol defaults, endpoint paths and header names.
 */
final class TypeSafe
{
    public const string VERSION = '0.2.1';

    public const string DEFAULT_BASE_URL = 'https://api.typesafe.ai';
    public const string DEFAULT_MODEL = 'jev-latest';

    /** Jev through OpenRouter, an alternative to calling TypeSafe directly. */
    public const string OPENROUTER_BASE_URL = 'https://openrouter.ai';

    /**
     * OpenRouter's model names. `~` marks a floating alias and pairs only with
     * `-latest`; a pinned build takes the plain prefix, e.g. `typesafe/jev-1.13`.
     * The bare `jev-latest` this SDK defaults to is accepted by both providers.
     */
    public const string OPENROUTER_MODEL_LATEST = '~typesafe/jev-latest';

    /** Timeout per attempt, in seconds. */
    public const float DEFAULT_TIMEOUT = 10.0;

    public const string SYSTEM_ONE_PATH = '/v1/systemone';
    public const string MODELS_PATH = '/v1/models';
    public const string OPENROUTER_DECISIONS_PATH = '/api/alpha/decisions';

    public const string SDK_HEADER = 'X-TypeSafe-SDK';
    public const string RUNTIME_HEADER = 'X-TypeSafe-Runtime';
    public const string RETRY_COUNT_HEADER = 'X-TypeSafe-Retry-Count';
    public const string REQUEST_ID_HEADER = 'x-typesafe-request-id';
    public const string GENERATION_ID_HEADER = 'x-generation-id';
    public const string RETRY_AFTER_HEADER = 'retry-after';
    public const string RETRY_AFTER_MS_HEADER = 'retry-after-ms';

    /** Longest raw response body echoed into an API error message. */
    public const int MAX_ERROR_BODY_LENGTH = 200;

    private function __construct() {}
}
