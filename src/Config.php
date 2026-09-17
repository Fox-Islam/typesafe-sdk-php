<?php

declare(strict_types=1);

namespace Phox\TypeSafe;

use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Enums\LogLevel;
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
    private ?string $apiKey;
    private string $baseUrl;
    private string $defaultModel;
    private float $timeout;

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    private RetryPolicy $retry;
    private LogLevel $logLevel;
    private LoggerInterface $logger;
    private ?Transport $transport = null;

    public function __construct(?string $apiKey = null, ?string $baseUrl = null, ?string $defaultModel = null)
    {
        $this->apiKey = Env::fallback($apiKey, Env::API_KEY);
        $this->baseUrl = rtrim(Env::fallback($baseUrl, Env::BASE_URL, TypeSafe::DEFAULT_BASE_URL) ?? '', '/');
        $this->defaultModel = Env::fallback($defaultModel, Env::DEFAULT_MODEL, TypeSafe::DEFAULT_MODEL) ?? '';
        $this->timeout = TypeSafe::DEFAULT_TIMEOUT;
        $this->retry = RetryPolicy::default();
        $this->logger = new NullLogger();

        $fromEnv = Env::read(Env::LOG_LEVEL);
        $this->logLevel = $fromEnv === null ? LogLevel::DEFAULT : LogLevel::parse($fromEnv, Env::LOG_LEVEL);
    }

    /**
     * @throws TypeSafeException No API key was supplied in code or in the environment.
     */
    public function requireApiKey(): string
    {
        return $this->apiKey ?? throw new TypeSafeException(sprintf(
            'No API key was provided. Pass one to the Client constructor or apiKey() method, or set the %s environment variable.',
            Env::API_KEY,
        ));
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
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
