<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Client;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Support\Env;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClientConfigTest extends ClientTestCase
{
    #[Test]
    public function it_falls_back_to_the_sdk_defaults(): void
    {
        $client = Client::make('key');

        self::assertSame('https://api.typesafe.ai', $client->getBaseUrl());
        self::assertSame('jev-latest', $client->getDefaultModel());
        self::assertSame(10.0, $client->getTimeout());
        self::assertSame(LogLevel::Warning, $client->getLogLevel());
    }

    #[Test]
    public function it_reads_settings_from_the_environment(): void
    {
        $_ENV[Env::API_KEY] = 'env-key';
        $_ENV[Env::BASE_URL] = 'https://staging.typesafe.ai/';
        $_ENV[Env::DEFAULT_MODEL] = 'jev-staging';
        $_ENV[Env::LOG_LEVEL] = 'debug';

        $client = Client::make();

        self::assertTrue($client->hasApiKey());
        self::assertSame('https://staging.typesafe.ai', $client->getBaseUrl());
        self::assertSame('jev-staging', $client->getDefaultModel());
        self::assertSame(LogLevel::Debug, $client->getLogLevel());
    }

    #[Test]
    public function values_given_in_code_beat_the_environment(): void
    {
        $_ENV[Env::BASE_URL] = 'https://staging.typesafe.ai';

        self::assertSame('https://self-hosted.example.com', Client::make('key', 'https://self-hosted.example.com')->getBaseUrl());
    }

    #[Test]
    public function blank_environment_values_are_ignored(): void
    {
        $_ENV[Env::DEFAULT_MODEL] = '   ';

        self::assertSame('jev-latest', Client::make('key')->getDefaultModel());
    }

    #[Test]
    public function a_missing_api_key_is_reported_when_a_request_is_sent(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('No API key was provided.');

        Client::make()->transport($this->transport)->systemOne()->state('hello')->noul('urgent')->send();
    }

    #[Test]
    public function an_unknown_log_level_names_the_valid_ones(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Invalid log level "chatty" from the logLevel() argument. Expected one of: debug, info, warn, error, off.');

        Client::make('key')->logLevel('chatty');
    }

    #[Test]
    public function an_unknown_log_level_in_the_environment_names_the_variable(): void
    {
        $_ENV[Env::LOG_LEVEL] = 'chatty';

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('from TYPESAFE_LOG_LEVEL');

        Client::make('key');
    }

    #[Test]
    public function a_non_positive_timeout_is_rejected(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('`timeout` must be a positive number of seconds, got 0.');

        Client::make('key')->timeout(0);
    }

    #[Test]
    public function the_timeout_is_passed_to_the_transport_per_attempt(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'usage' => [], 'answers' => []]);

        $this->client()->timeout(30)->systemOne()->state('hello')->noul('urgent')->timeout(2.5)->send();

        self::assertSame([2.5], $this->transport->timeouts);
    }

    #[Test]
    public function a_per_call_retry_override_leaves_the_client_policy_alone(): void
    {
        $client = $this->client()->retry(fn (RetryPolicy $policy): RetryPolicy => $policy->maxRetries(5));

        $this->transport->queue(['model' => 'jev-latest', 'usage' => [], 'answers' => []]);

        $client->systemOne()
            ->state('hello')
            ->noul('urgent')
            ->retry(fn (RetryPolicy $policy): RetryPolicy => $policy->maxRetries(0))
            ->send();

        self::assertSame(5, $client->getRetry()->getMaxRetries());
    }

    #[Test]
    public function the_api_key_stays_out_of_debug_output(): void
    {
        $dumped = print_r(Client::make('sk-secret-value'), true);

        self::assertStringNotContainsString('sk-secret-value', $dumped);
        self::assertStringContainsString('hasApiKey', $dumped);
    }
}
