<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Http;

use GuzzleHttp\Psr7\Request;
use Phox\TypeSafe\Config;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Exceptions\ApiException;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\TimeoutException;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Support\Headers;
use Phox\TypeSafe\Support\Json;
use Phox\TypeSafe\Support\Runtime;
use Phox\TypeSafe\TypeSafe;

/**
 * Turns a call into HTTP: signs it, sends it, retries what is worth retrying,
 * and logs each attempt.
 *
 * @internal
 */
final class Requester
{
    private static int $requests = 0;

    /** @var (callable(float): void) */
    private $sleeper;

    /**
     * @param (callable(float): void)|null $sleeper Overridable so tests need not wait out a backoff.
     */
    public function __construct(
        private readonly Config $config,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @throws ApiException The API returned a non-2xx response the policy would not retry.
     * @throws TimeoutException An attempt ran out of time and retries are exhausted or disabled.
     * @throws ConnectionException The request never completed and retries are exhausted or disabled.
     */
    public function send(string $method, string $path, ?array $body, RequestOptions $options): ApiResponse
    {
        $url = $this->config->getBaseUrl() . $path;
        $timeout = $options->timeout ?? $this->config->getTimeout();
        $policy = $options->retry ?? $this->config->getRetry();
        $payload = $body === null ? null : Json::encode($body);

        // Caller headers go first so they cannot displace auth or the content type.
        $headers = Headers::merge($this->config->getDefaultHeaders(), $options->headers, [
            'Authorization' => 'Bearer ' . $this->config->requireApiKey(),
            'Accept' => 'application/json',
            'User-Agent' => $this->agent(),
            TypeSafe::SDK_HEADER => $this->agent(),
            TypeSafe::RUNTIME_HEADER => Runtime::describe(),
            'Content-Type' => $payload === null ? null : 'application/json',
            TypeSafe::RETRY_COUNT_HEADER => null,
        ]);

        // Numbered so concurrent requests, and the attempts within one, can be told apart in the log.
        $tag = sprintf('#%d %s %s', ++self::$requests, $method, $path);

        for ($attempt = 0; ; $attempt++) {
            $retriesLeft = $policy->getMaxRetries() - $attempt;
            $attemptHeaders = $attempt === 0
                ? $headers
                : Headers::merge($headers, [TypeSafe::RETRY_COUNT_HEADER => (string) $attempt]);

            $this->log(LogLevel::Debug, "{$tag} -> {$url}", [
                'headers' => Headers::redact($attemptHeaders),
                'body' => $body,
            ]);

            $started = microtime(true);

            try {
                $response = $this->config->getTransport()->send(
                    new Request($method, $url, $attemptHeaders, $payload),
                    $timeout,
                );
            } catch (ConnectionException $exception) {
                $this->log(LogLevel::Info, sprintf('%s failed after %s: %s', $tag, self::elapsed($started), $exception->getMessage()));

                if ($retriesLeft <= 0 || ! self::retriesException($exception, $policy)) {
                    throw $exception;
                }

                $this->backOff($tag, $attempt, $retriesLeft, $exception->getMessage(), [], $policy);
                continue;
            }

            $status = $response->getStatusCode();
            $responseHeaders = Headers::flatten($response->getHeaders());
            $decoded = Json::decode((string) $response->getBody());
            $requestId = Headers::get($responseHeaders, $this->config->getProvider()->requestIdHeader());

            $this->log(LogLevel::Info, sprintf(
                '%s <- %d in %s%s',
                $tag,
                $status,
                self::elapsed($started),
                $requestId === null ? '' : " (request {$requestId})",
            ));

            if ($status >= 200 && $status < 300) {
                $this->log(LogLevel::Debug, "{$tag} <- body", ['body' => $decoded]);

                return new ApiResponse($status, $responseHeaders, $decoded, $response);
            }

            $this->log(LogLevel::Debug, "{$tag} <- error body", ['body' => $decoded]);
            $error = ApiException::fromResponse($status, $decoded, $responseHeaders);

            if ($retriesLeft <= 0 || ! $policy->retriesStatus($status)) {
                throw $error;
            }

            $this->backOff($tag, $attempt, $retriesLeft, (string) $status, $responseHeaders, $policy);
        }
    }

    private function agent(): string
    {
        return 'typesafe-sdk-php/' . TypeSafe::VERSION;
    }

    /**
     * @param array<string, string> $headers
     */
    private function backOff(string $tag, int $attempt, int $retriesLeft, string $reason, array $headers, RetryPolicy $policy): void
    {
        $delay = $policy->delayFor($attempt, $headers);

        $this->log(LogLevel::Info, sprintf(
            '%s retrying in %ss (retry %d/%d) after %s',
            $tag,
            $delay,
            $attempt + 1,
            $attempt + $retriesLeft,
            $reason,
        ));

        ($this->sleeper)($delay);
    }

    private static function retriesException(ConnectionException $exception, RetryPolicy $policy): bool
    {
        return $exception instanceof TimeoutException
            ? $policy->retriesTimeouts()
            : $policy->retriesConnectionErrors();
    }

    private static function elapsed(float $started): string
    {
        return round((microtime(true) - $started) * 1000) . 'ms';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(LogLevel $at, string $message, array $context = []): void
    {
        if (! $this->config->getLogLevel()->allows($at)) {
            return;
        }

        $this->config->getLogger()->log($at->toPsr(), "[typesafe-sdk] {$message}", $context);
    }
}
