<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Client;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\InternalServerException;
use Phox\TypeSafe\Exceptions\NotFoundException;
use Phox\TypeSafe\Exceptions\TimeoutException;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RetryTest extends ClientTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return ['model' => 'jev-latest', 'usage' => [], 'answers' => ['urgent' => ['type' => 'noul', 'noul' => 0.5]]];
    }

    /**
     * A client whose backoff is instant, so retry behaviour can be tested without waiting.
     */
    private function instantClient(): Client
    {
        return $this->client()->retry(fn (RetryPolicy $policy): RetryPolicy => $policy->initialBackoff(0)->respectRetryAfter(false));
    }

    #[Test]
    public function a_retryable_status_is_retried_until_it_succeeds(): void
    {
        $this->transport
            ->queue(['error' => 'busy'], 503)
            ->queue($this->payload());

        $response = $this->instantClient()->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame(0.5, $response->noul('urgent')->noul());
        self::assertSame(2, $this->transport->callCount());
    }

    #[Test]
    public function retries_are_numbered_with_a_header(): void
    {
        $this->transport
            ->queue(['error' => 'busy'], 503)
            ->queue(['error' => 'busy'], 503)
            ->queue($this->payload());

        $this->instantClient()->systemOne()->state('hello')->noul('urgent')->send();

        self::assertFalse($this->transport->requests[0]->hasHeader('X-TypeSafe-Retry-Count'));
        self::assertSame('1', $this->transport->requests[1]->getHeaderLine('X-TypeSafe-Retry-Count'));
        self::assertSame('2', $this->transport->requests[2]->getHeaderLine('X-TypeSafe-Retry-Count'));
    }

    #[Test]
    public function retries_stop_at_the_configured_limit(): void
    {
        $this->transport
            ->queue(['error' => 'busy'], 503)
            ->queue(['error' => 'busy'], 503)
            ->queue(['error' => 'busy'], 503);

        $this->expectException(InternalServerException::class);

        try {
            $this->instantClient()->systemOne()->state('hello')->noul('urgent')->send();
        } finally {
            self::assertSame(3, $this->transport->callCount());
        }
    }

    #[Test]
    public function a_status_outside_the_policy_is_not_retried(): void
    {
        $this->transport->queue(['error' => 'gone'], 404);

        $this->expectException(NotFoundException::class);

        try {
            $this->instantClient()->systemOne()->state('hello')->noul('urgent')->send();
        } finally {
            self::assertSame(1, $this->transport->callCount());
        }
    }

    #[Test]
    public function connection_failures_and_timeouts_are_retried(): void
    {
        $this->transport
            ->queueFailure(new ConnectionException('Connection error: DNS failure'))
            ->queueFailure(new TimeoutException(10.0))
            ->queue($this->payload());

        $this->instantClient()->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame(3, $this->transport->callCount());
    }

    #[Test]
    public function timeout_retries_can_be_turned_off_on_their_own(): void
    {
        $this->transport->queueFailure(new TimeoutException(2.0));

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Request timed out after 2s.');

        try {
            $this->instantClient()
                ->retry(fn (RetryPolicy $policy): RetryPolicy => $policy->retryTimeouts(false))
                ->systemOne()->state('hello')->noul('urgent')->send();
        } finally {
            self::assertSame(1, $this->transport->callCount());
        }
    }

    #[Test]
    public function a_per_call_policy_can_disable_retries(): void
    {
        $this->transport->queue(['error' => 'busy'], 503);

        $this->expectException(InternalServerException::class);

        try {
            $this->instantClient()->systemOne()
                ->state('hello')->noul('urgent')
                ->retry(RetryPolicy::none())
                ->send();
        } finally {
            self::assertSame(1, $this->transport->callCount());
        }
    }

    #[Test]
    public function backoff_grows_exponentially_up_to_the_ceiling(): void
    {
        $policy = RetryPolicy::default()->jitter(0);

        self::assertSame(0.5, $policy->delayFor(0));
        self::assertSame(1.0, $policy->delayFor(1));
        self::assertSame(2.0, $policy->delayFor(2));
        self::assertSame(5.0, $policy->delayFor(10));
    }

    #[Test]
    public function jitter_subtracts_a_fraction_of_the_delay(): void
    {
        $policy = RetryPolicy::default()->jitter(0.5);

        self::assertSame(0.25, $policy->delayFor(0, random: static fn (): float => 1.0));
        self::assertSame(0.5, $policy->delayFor(0, random: static fn (): float => 0.0));
    }

    #[Test]
    public function a_server_delay_wins_over_backoff(): void
    {
        $policy = RetryPolicy::default()->jitter(0);

        self::assertSame(3.0, $policy->delayFor(0, ['Retry-After' => '3']));
        self::assertSame(1.5, $policy->delayFor(0, ['retry-after-ms' => '1500']));
    }

    #[Test]
    public function a_server_delay_beyond_the_ceiling_falls_back_to_backoff(): void
    {
        $policy = RetryPolicy::default()->jitter(0)->maxRetryAfter(30);

        self::assertSame(0.5, $policy->delayFor(0, ['Retry-After' => '120']));
    }

    #[Test]
    public function a_server_delay_is_ignored_when_the_policy_says_so(): void
    {
        $policy = RetryPolicy::default()->jitter(0)->respectRetryAfter(false);

        self::assertSame(0.5, $policy->delayFor(0, ['Retry-After' => '3']));
    }

    #[Test]
    public function an_http_date_retry_after_is_read_as_a_delay(): void
    {
        $policy = RetryPolicy::default()->jitter(0);
        $delay = $policy->delayFor(0, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 20)]);

        self::assertEqualsWithDelta(20.0, $delay, 1.0);
    }

    #[Test]
    public function an_unusable_retry_after_falls_back_to_backoff(): void
    {
        $policy = RetryPolicy::default()->jitter(0);

        self::assertSame(0.5, $policy->delayFor(0, ['Retry-After' => 'soon']));
        self::assertSame(0.5, $policy->delayFor(0, ['Retry-After' => '-5']));
    }

    #[Test]
    public function the_retried_statuses_can_be_replaced(): void
    {
        $this->transport->queue(['error' => 'teapot'], 418)->queue($this->payload());

        $this->instantClient()
            ->retry(fn (RetryPolicy $policy): RetryPolicy => $policy->retryStatuses(418))
            ->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame(2, $this->transport->callCount());
    }

    #[Test]
    public function invalid_policy_values_are_rejected(): void
    {
        $policy = RetryPolicy::default();

        self::assertThrows(fn () => $policy->maxRetries(-1), '`maxRetries` must be zero or greater, got -1.');
        self::assertThrows(fn () => $policy->jitter(1.5), '`jitter` must be between 0 and 1, got 1.5.');
        self::assertThrows(fn () => $policy->initialBackoff(-1), '`initialBackoff` must be a non-negative number of seconds, got -1.');
        self::assertThrows(fn () => $policy->retryStatuses(42), '`retryStatuses` must contain HTTP status codes, got 42.');
    }

    private static function assertThrows(callable $call, string $message): void
    {
        try {
            $call();
            self::fail("Expected: {$message}");
        } catch (TypeSafeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
