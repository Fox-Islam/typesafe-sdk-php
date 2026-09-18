<?php

declare(strict_types=1);

namespace Phox\TypeSafe;

use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Enums\Provider;
use Phox\TypeSafe\Http\Psr18Transport;
use Phox\TypeSafe\Http\Requester;
use Phox\TypeSafe\Requests\Models;
use Phox\TypeSafe\Requests\SystemOne;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Support\Env;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Entry point for the TypeSafe AI API.
 *
 * Settings supplied in code win over environment variables
 * (`TYPESAFE_API_KEY`, `TYPESAFE_BASE_URL`, `TYPESAFE_DEFAULT_MODEL`,
 * `TYPESAFE_LOG_LEVEL`, `TYPESAFE_PROVIDER`), which win over the SDK defaults.
 *
 * Jev can be reached directly from TypeSafe or through OpenRouter, and nothing
 * a caller writes changes between them:
 *
 * ```php
 * Client::make()->openRouter();          // reads OPENROUTER_API_KEY
 * ```
 *
 * ```php
 * $client = Client::make()->defaultModel('jev-latest');
 *
 * $answers = $client->systemOne()
 *     ->state('I was charged twice. Please fix this ASAP.')
 *     ->choice('category', ['billing', 'technical', 'other'], 'What is this ticket about?')
 *     ->send();
 *
 * $answers->choice('category')->choice();
 * ```
 *
 * The configuration setters mutate the client and return it, so a client can be
 * built up in one chain and reused. Per-call overrides live on the request
 * builders and never touch the client.
 */
final class Client
{
    private readonly Config $config;
    private readonly Requester $requester;

    /**
     * @param string|null $apiKey Falls back to the provider's key variable, `TYPESAFE_API_KEY` or `OPENROUTER_API_KEY`.
     * @param string|null $baseUrl Falls back to `TYPESAFE_BASE_URL`, then the provider's own host.
     * @param string|null $defaultModel Falls back to `TYPESAFE_DEFAULT_MODEL`, then `jev-latest`.
     * @param Provider|null $provider Falls back to `TYPESAFE_PROVIDER`, then TypeSafe.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $defaultModel = null,
        ?Provider $provider = null,
    ) {
        $this->config = new Config($apiKey, $baseUrl, $defaultModel, $provider);
        $this->requester = new Requester($this->config);
    }

    /**
     * The same as the constructor, so configuration can be chained from a
     * single expression.
     */
    public static function make(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $defaultModel = null,
        ?Provider $provider = null,
    ): self {
        return new self($apiKey, $baseUrl, $defaultModel, $provider);
    }

    /**
     * Answer named questions about text or structured state.
     */
    public function systemOne(): SystemOne
    {
        return new SystemOne($this->requester, $this->config);
    }

    /**
     * The models available to the account.
     */
    public function models(): Models
    {
        return new Models($this->requester, $this->config);
    }

    public function apiKey(string $apiKey): self
    {
        $this->config->setApiKey($apiKey);

        return $this;
    }

    /**
     * Send calls to a different provider. The base URL and the API key follow it
     * unless you set either of them yourself, so switching is a one-liner.
     */
    public function provider(Provider|string $provider): self
    {
        $this->config->setProvider(
            $provider instanceof Provider ? $provider : Provider::parse($provider, 'the provider() argument'),
        );

        return $this;
    }

    /**
     * Reach Jev through OpenRouter instead of calling TypeSafe directly, reading
     * `OPENROUTER_API_KEY` unless a key was set in code.
     */
    public function openRouter(): self
    {
        return $this->provider(Provider::OpenRouter);
    }

    /**
     * The API root. Trailing slashes are dropped.
     */
    public function baseUrl(string $baseUrl): self
    {
        $this->config->setBaseUrl($baseUrl);

        return $this;
    }

    /**
     * The model used by requests that do not name one.
     */
    public function defaultModel(string $model): self
    {
        $this->config->setDefaultModel($model);

        return $this;
    }

    /**
     * Timeout per attempt, in seconds. Retries each get the full timeout, so
     * this is not a budget for the call as a whole.
     */
    public function timeout(float $seconds): self
    {
        $this->config->setTimeout($seconds);

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->config->setDefaultHeaders($this->config->getDefaultHeaders() + [$name => $value]);

        return $this;
    }

    /**
     * Headers sent with every request. Per-call headers take precedence.
     *
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $this->config->setDefaultHeaders(array_merge($this->config->getDefaultHeaders(), $headers));

        return $this;
    }

    /**
     * Replace the retry policy, either with one you built or with a callback
     * that receives the current policy and returns a modified copy.
     *
     * @param RetryPolicy|(callable(RetryPolicy): RetryPolicy) $retry
     */
    public function retry(RetryPolicy|callable $retry): self
    {
        $this->config->setRetry($retry instanceof RetryPolicy ? $retry : $retry($this->config->getRetry()));

        return $this;
    }

    /**
     * Where the SDK's own logs go. Nothing is logged until a logger is set.
     */
    public function logger(LoggerInterface $logger): self
    {
        $this->config->setLogger($logger);

        return $this;
    }

    /**
     * How much the SDK logs. `Info` writes a line per attempt; `Debug` adds
     * headers and bodies. Credential headers are redacted; bodies are not.
     */
    public function logLevel(LogLevel|string $level): self
    {
        $this->config->setLogLevel(
            $level instanceof LogLevel ? $level : LogLevel::parse($level, 'the logLevel() argument'),
        );

        return $this;
    }

    /**
     * Send requests through your own transport, for another HTTP stack or for tests.
     */
    public function transport(Transport $transport): self
    {
        $this->config->setTransport($transport);

        return $this;
    }

    /**
     * Send requests through a PSR-18 client.
     *
     * PSR-18 has no per-request timeout, so {@see timeout()} is not enforced for
     * these requests: configure one on the client you pass in.
     */
    public function httpClient(ClientInterface $client): self
    {
        return $this->transport(new Psr18Transport($client));
    }

    public function getProvider(): Provider
    {
        return $this->config->getProvider();
    }

    public function getBaseUrl(): string
    {
        return $this->config->getBaseUrl();
    }

    public function getDefaultModel(): string
    {
        return $this->config->getDefaultModel();
    }

    public function getTimeout(): float
    {
        return $this->config->getTimeout();
    }

    /** @return array<string, string> */
    public function getDefaultHeaders(): array
    {
        return $this->config->getDefaultHeaders();
    }

    public function getRetry(): RetryPolicy
    {
        return $this->config->getRetry();
    }

    public function getLogLevel(): LogLevel
    {
        return $this->config->getLogLevel();
    }

    /**
     * Whether an API key was supplied in code or found in the provider's key variable.
     * Requests fail with a {@see \Phox\TypeSafe\Exceptions\TypeSafeException} when it is missing.
     */
    public function hasApiKey(): bool
    {
        return $this->config->hasApiKey();
    }

    /**
     * Keep the API key out of stack traces, `var_dump()` and error reports.
     *
     * @return array{provider: string, baseUrl: string, defaultModel: string, timeout: float, logLevel: string, hasApiKey: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->config->getProvider()->value,
            'baseUrl' => $this->config->getBaseUrl(),
            'defaultModel' => $this->config->getDefaultModel(),
            'timeout' => $this->config->getTimeout(),
            'logLevel' => $this->config->getLogLevel()->value,
            'hasApiKey' => $this->config->hasApiKey(),
        ];
    }

    /**
     * The environment variable names the client reads.
     *
     * @return array{apiKey: string, openRouterApiKey: string, provider: string, baseUrl: string, defaultModel: string, logLevel: string}
     */
    public static function environmentVariables(): array
    {
        return [
            'apiKey' => Env::API_KEY,
            'openRouterApiKey' => Env::OPENROUTER_API_KEY,
            'provider' => Env::PROVIDER,
            'baseUrl' => Env::BASE_URL,
            'defaultModel' => Env::DEFAULT_MODEL,
            'logLevel' => Env::LOG_LEVEL,
        ];
    }
}
