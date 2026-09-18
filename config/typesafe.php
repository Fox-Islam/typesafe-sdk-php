<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | Where System One calls are sent: "typesafe" to call TypeSafe directly, or
    | "openrouter" to reach Jev through OpenRouter's decisions endpoint. The
    | request and answer bodies are the same on both, so this only decides the
    | host, the path and which key below is used.
    |
    */

    'provider' => env('TYPESAFE_PROVIDER', 'typesafe'),

    /*
    |--------------------------------------------------------------------------
    | API keys
    |--------------------------------------------------------------------------
    |
    | One per provider; the one matching the provider above is used. Reading
    | them here rather than leaving the SDK to look them up at call time is what
    | keeps the package working under `config:cache`, where the .env is never
    | loaded and a call-time env lookup would quietly find nothing.
    |
    */

    'keys' => [
        'typesafe' => env('TYPESAFE_API_KEY'),
        'openrouter' => env('OPENROUTER_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | "jev-latest" resolves to the current build on either provider. Pin to a
    | build, e.g. "typesafe/jev-1.13", to stop answers moving underneath a
    | calibrated threshold.
    |
    */

    'model' => env('TYPESAFE_DEFAULT_MODEL', 'jev-latest'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | Null follows the provider. Set this only to point at a proxy or a
    | self-hosted endpoint; it then stays put when the provider changes.
    |
    */

    'base_url' => env('TYPESAFE_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts and retries
    |--------------------------------------------------------------------------
    |
    | The timeout is per attempt, not a budget for the call as a whole: each
    | retry gets the full timeout again.
    |
    */

    'timeout' => (float) env('TYPESAFE_TIMEOUT', 10.0),

    'retries' => env('TYPESAFE_RETRIES'),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | "channel" names a Laravel log channel, or null for the default one; set
    | "enabled" to false to log nothing at all. Levels: error, warn, info, debug
    | and off. Credential headers are redacted; request and response bodies are
    | not, so debug will write your state and answers to the log.
    |
    */

    'log' => [
        'enabled' => (bool) env('TYPESAFE_LOG_ENABLED', true),
        'channel' => env('TYPESAFE_LOG_CHANNEL'),
        'level' => env('TYPESAFE_LOG_LEVEL', 'warn'),
    ],
];
