<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Retry;

use Phox\TypeSafe\Exceptions\TypeSafeException;

/**
 * How failed attempts are retried. All delays are in seconds.
 *
 * Setters return a modified copy, so a policy can be shared by the client and
 * narrowed per request without the two affecting each other:
 *
 * ```php
 * $client->systemOne()->retry(fn (RetryPolicy $policy) => $policy->maxRetries(0));
 * ```
 */
final class RetryPolicy
{
    /** @var list<int> */
    private array $statuses;

    private function __construct(
        private int $maxRetries = 2,
        private float $initialBackoff = 0.5,
        private float $maxBackoff = 5.0,
        private float $jitter = 0.25,
        private bool $respectRetryAfter = true,
        private float $maxRetryAfter = 60.0,
        private bool $retryConnectionErrors = true,
        private bool $retryTimeouts = true,
    ) {
        $this->statuses = [408, 429, ...range(500, 599)];
    }

    /** Two retries, exponential backoff from 500ms, on 408, 429 and 5xx. */
    public static function default(): self
    {
        return new self();
    }

    /** A policy that sends each request exactly once. */
    public static function none(): self
    {
        return new self(maxRetries: 0);
    }

    /**
     * Maximum retries after the first attempt; `0` sends the request once.
     */
    public function maxRetries(int $retries): self
    {
        if ($retries < 0) {
            throw new TypeSafeException("`maxRetries` must be zero or greater, got {$retries}.");
        }

        return $this->with(fn (self $policy) => $policy->maxRetries = $retries);
    }

    /**
     * First backoff delay in seconds, doubled each attempt up to `maxBackoff`.
     */
    public function initialBackoff(float $seconds): self
    {
        return $this->with(fn (self $policy) => $policy->initialBackoff = self::assertDuration('initialBackoff', $seconds));
    }

    /** Ceiling for the exponential backoff, in seconds. */
    public function maxBackoff(float $seconds): self
    {
        return $this->with(fn (self $policy) => $policy->maxBackoff = self::assertDuration('maxBackoff', $seconds));
    }

    /**
     * Fraction of each backoff delay randomly subtracted, from 0 to 1.
     */
    public function jitter(float $fraction): self
    {
        if (! is_finite($fraction) || $fraction < 0 || $fraction > 1) {
            throw new TypeSafeException("`jitter` must be between 0 and 1, got {$fraction}.");
        }

        return $this->with(fn (self $policy) => $policy->jitter = $fraction);
    }

    /**
     * Replace the retried status codes.
     */
    public function retryStatuses(int ...$statuses): self
    {
        foreach ($statuses as $status) {
            if ($status < 100 || $status > 999) {
                throw new TypeSafeException("`retryStatuses` must contain HTTP status codes, got {$status}.");
            }
        }

        return $this->with(fn (self $policy) => $policy->statuses = array_values(array_unique($statuses)));
    }

    /** Honour `Retry-After` and `retry-after-ms` response headers. */
    public function respectRetryAfter(bool $respect = true): self
    {
        return $this->with(fn (self $policy) => $policy->respectRetryAfter = $respect);
    }

    /**
     * Longest server-requested delay to honour, in seconds; anything longer
     * falls back to the SDK's own backoff.
     */
    public function maxRetryAfter(float $seconds): self
    {
        return $this->with(fn (self $policy) => $policy->maxRetryAfter = self::assertDuration('maxRetryAfter', $seconds));
    }

    /** Retry {@see \Phox\TypeSafe\Exceptions\ConnectionException}. */
    public function retryConnectionErrors(bool $retry = true): self
    {
        return $this->with(fn (self $policy) => $policy->retryConnectionErrors = $retry);
    }

    /** Retry {@see \Phox\TypeSafe\Exceptions\TimeoutException}. */
    public function retryTimeouts(bool $retry = true): self
    {
        return $this->with(fn (self $policy) => $policy->retryTimeouts = $retry);
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getInitialBackoff(): float
    {
        return $this->initialBackoff;
    }

    public function getMaxBackoff(): float
    {
        return $this->maxBackoff;
    }

    public function getJitter(): float
    {
        return $this->jitter;
    }

    /** @return list<int> */
    public function getRetryStatuses(): array
    {
        return $this->statuses;
    }

    public function getMaxRetryAfter(): float
    {
        return $this->maxRetryAfter;
    }

    public function respectsRetryAfter(): bool
    {
        return $this->respectRetryAfter;
    }

    public function retriesConnectionErrors(): bool
    {
        return $this->retryConnectionErrors;
    }

    public function retriesTimeouts(): bool
    {
        return $this->retryTimeouts;
    }

    public function retriesStatus(int $status): bool
    {
        return in_array($status, $this->statuses, true);
    }

    /**
     * Seconds to wait before a zero-based retry attempt.
     *
     * An allowed server delay wins; otherwise capped exponential backoff has a
     * random fraction of itself subtracted.
     *
     * @param array<string, string> $headers Response headers, when the attempt got that far.
     * @param (callable(): float)|null $random Source of jitter, from 0 to 1; overridable in tests.
     */
    public function delayFor(int $attempt, array $headers = [], ?callable $random = null): float
    {
        if ($this->respectRetryAfter && $headers !== []) {
            $retryAfter = RetryAfter::parse($headers);
            if ($retryAfter !== null && $retryAfter <= $this->maxRetryAfter) {
                return $retryAfter;
            }
        }

        $exponential = min($this->initialBackoff * (2 ** $attempt), $this->maxBackoff);
        $random ??= static fn (): float => mt_rand() / mt_getrandmax();

        return round($exponential * (1 - $random() * $this->jitter), 3);
    }

    /**
     * @param callable(self): mixed $mutate
     */
    private function with(callable $mutate): self
    {
        $clone = clone $this;
        $mutate($clone);

        return $clone;
    }

    private static function assertDuration(string $name, float $seconds): float
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new TypeSafeException("`{$name}` must be a non-negative number of seconds, got {$seconds}.");
        }

        return $seconds;
    }
}
