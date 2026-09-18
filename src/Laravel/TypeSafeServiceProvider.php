<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Throwable;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Enums\Provider;
use Phox\TypeSafe\Retry\RetryPolicy;

/**
 * Registers {@see Client} as a singleton built from `config/typesafe.php`.
 *
 * Settings come from config, and anything left null there falls through to the
 * environment variables the SDK reads on its own — so an app that only sets
 * `TYPESAFE_API_KEY` needs no config file at all, while one that publishes the
 * config keeps working under `config:cache`, where the .env is never loaded.
 *
 * Auto-discovered. Nothing here calls out, so registering it is free whether or
 * not a key is configured.
 */
final class TypeSafeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->configPath(), 'typesafe');

        $this->app->singleton(Client::class, fn (): Client => $this->client());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([$this->configPath() => $this->app->configPath('typesafe.php')], 'typesafe-config');
        }
    }

    private function client(): Client
    {
        $client = Client::make(
            $this->string('typesafe.keys.'.$this->provider()->value),
            $this->string('typesafe.base_url'),
            $this->string('typesafe.model'),
            $this->provider(),
        );

        return $this->withLogging($this->withTransportSettings($client));
    }

    private function withTransportSettings(Client $client): Client
    {
        $timeout = config('typesafe.timeout');

        if (is_numeric($timeout) && (float) $timeout > 0) {
            $client->timeout((float) $timeout);
        }

        $retries = config('typesafe.retries');

        return is_numeric($retries)
            ? $client->retry(fn (RetryPolicy $policy): RetryPolicy => $policy->maxRetries((int) $retries))
            : $client;
    }

    private function withLogging(Client $client): Client
    {
        if (config('typesafe.log.enabled') === false) {
            return $client->logLevel(LogLevel::Off);
        }

        $level = $this->string('typesafe.log.level');

        if ($level !== null) {
            $client->logLevel($level);
        }

        return $this->withLogger($client);
    }

    /**
     * Resolving a channel must never be able to fail the client.
     *
     * A host app that swaps the Log facade for a test double breaks this in two
     * different ways — a partial mock answers channel() with null, a strict one
     * throws BadMethodCallException — and either turned an unrelated assertion
     * in the host's own suite into a failure from inside this package. The
     * client simply goes without a logger instead.
     */
    private function withLogger(Client $client): Client
    {
        try {
            /** @var mixed $logger Declared as a LoggerInterface, but a double may answer otherwise. */
            $logger = Log::channel($this->string('typesafe.log.channel'));
        } catch (Throwable) {
            return $client;
        }

        return $logger instanceof LoggerInterface ? $client->logger($logger) : $client;
    }

    private function provider(): Provider
    {
        $configured = $this->string('typesafe.provider');

        return $configured === null ? Provider::TypeSafe : Provider::parse($configured, 'config/typesafe.php');
    }

    /**
     * Config values are only honoured when actually set, so that a published
     * file which leaves a key empty falls through to the environment rather
     * than overriding it with an empty string.
     */
    private function string(string $key): ?string
    {
        $value = config($key);

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        return trim((string) $value) === '' ? null : trim((string) $value);
    }

    private function configPath(): string
    {
        return dirname(__DIR__, 2).'/config/typesafe.php';
    }
}
