<?php

declare(strict_types=1);

namespace Phox\TypeSafe;

use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Enums\Provider;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Http\GuzzleTransport;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Support\Env;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolved client settings. Values supplied in code win over environment
 * variables, which win over the SDK defaults.
 *
 * @internal Reached through {@see Client}, which owns the fluent setters.
 */
final class Config
{
    private Provider $provider;
    private ?string $apiKey;
    private string $baseUrl;
    private string $defaultModel;
    private float $timeout;

    /** Whether the key and base URL were pinned by the caller, so switching provider must not move them. */
    private bool $apiKeyPinned;
    private bool $baseUrlPinned;

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    private RetryPolicy $retry;
    private LogLevel $logLevel;
    private LoggerInterface $logger;
    private ?Transport $transport = null;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $defaultModel = null,
        ?Provider $provider = null,
    ) {
        $fromEnv = Env::read(Env::PROVIDER);
        $this->provider = $provider
            ?? ($fromEnv === null ? Provider::TypeSafe : Provider::parse($fromEnv, Env::PROVIDER));

        $this->apiKeyPinned = $apiKey !== null;
        $this->baseUrlPinned = $baseUrl !== null || Env::read(Env::BASE_URL) !== null;

        $this->apiKey = $apiKey ?? Env::read($this->provider->apiKeyEnv());
        $this->baseUrl = rtrim(Env::fallback($baseUrl, Env::BASE_URL, $this->provider->baseUrl()) ?? '', '/');
        $this->defaultModel = Env::fallback($defaultModel, Env::DEFAULT_MODEL, TypeSafe::DEFAULT_MODEL) ?? '';
        $this->timeout = TypeSafe::DEFAULT_TIMEOUT;
        $this->retry = RetryPolicy::default();
        $this->logger = new NullLogger();

        $logLevel = Env::read(Env::LOG_LEVEL);
        $this->logLevel = $logLevel === null ? LogLevel::DEFAULT : LogLevel::parse($logLevel, Env::LOG_LEVEL);
    }

    /**
     * @throws TypeSafeException No API key was supplied in code or in the environment.
     */
    public function requireApiKey(): string
    {
        return $this->apiKey ?? throw new TypeSafeException(sprintf(
            'No API key was provided. Pass one to the Client constructor or apiKey() method, or set the %s environment variable.',
            $this->provider->apiKeyEnv(),
        ));
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
        $this->apiKeyPinned = true;
    }

    public function getProvider(): Provider
    {
        return $this->provider;
    }

    /**
     * Switch providers, moving the base URL and the key with it unless the
     * caller pinned either of them.
     */
    public function setProvider(Provider $provider): void
    {
        $this->provider = $provider;

        if (! $this->baseUrlPinned) {
            $this->baseUrl = rtrim($provider->baseUrl(), '/');
        }

        if (! $this->apiKeyPinned) {
            $this->apiKey = Env::read($provider->apiKeyEnv());
        }
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->baseUrlPinned = true;
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel;
    }

    public function setDefaultModel(string $model): void
    {
        $this->defaultModel = $model;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setTimeout(float $seconds): void
    {
        $this->timeout = self::assertTimeout($seconds);
    }

    /** @return array<string, string> */
    public function getDefaultHeaders(): array
    {
        return $this->defaultHeaders;
    }

    /** @param array<string, string> $headers */
    public function setDefaultHeaders(array $headers): void
    {
        $this->defaultHeaders = $headers;
    }

    public function getRetry(): RetryPolicy
    {
        return $this->retry;
    }

    public function setRetry(RetryPolicy $retry): void
    {
        $this->retry = $retry;
    }

    public function getLogLevel(): LogLevel
    {
        return $this->logLevel;
    }

    public function setLogLevel(LogLevel $level): void
    {
        $this->logLevel = $level;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /** The transport, created on first use so a custom one can still be set. */
    public function getTransport(): Transport
    {
        return $this->transport ??= new GuzzleTransport();
    }

    public function setTransport(Transport $transport): void
    {
        $this->transport = $transport;
    }

    public static function assertTimeout(float $seconds): float
    {
        if (! is_finite($seconds) || $seconds <= 0) {
            throw new TypeSafeException("`timeout` must be a positive number of seconds, got {$seconds}.");
        }

        return $seconds;
    }
}
