<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Exceptions\ApiException;
use Phox\TypeSafe\Exceptions\AuthenticationException;
use Phox\TypeSafe\Exceptions\BadRequestException;
use Phox\TypeSafe\Exceptions\InternalServerException;
use Phox\TypeSafe\Exceptions\NotFoundException;
use Phox\TypeSafe\Exceptions\PermissionDeniedException;
use Phox\TypeSafe\Exceptions\RateLimitException;
use Phox\TypeSafe\Exceptions\UnprocessableEntityException;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class ErrorTest extends ClientTestCase
{
    /**
     * @return array<string, array{int, class-string<ApiException>}>
     */
    public static function statuses(): array
    {
        return [
            'HTTP 400' => [400, BadRequestException::class],
            'HTTP 401' => [401, AuthenticationException::class],
            'HTTP 403' => [403, PermissionDeniedException::class],
            'HTTP 404' => [404, NotFoundException::class],
            'HTTP 422' => [422, UnprocessableEntityException::class],
            'HTTP 429' => [429, RateLimitException::class],
            'HTTP 500' => [500, InternalServerException::class],
            'HTTP 503' => [503, InternalServerException::class],
        ];
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function each_status_gets_its_own_exception_class(int $status, string $expected): void
    {
        $this->transport->queue(['error' => 'nope'], $status);

        try {
            $this->client()
                ->retry(fn ($policy) => $policy->maxRetries(0))
                ->systemOne()->state('hello')->noul('urgent')->send();
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame($status, $exception->getStatus());
            self::assertSame($status, $exception->getCode());
            self::assertSame("{$status} nope", $exception->getMessage());
        }
    }

    #[Test]
    public function it_reads_the_message_out_of_a_nested_error_object(): void
    {
        $this->transport->queue(['error' => ['message' => 'Question "urgency" is malformed']], 400);

        $this->expectExceptionMessage('400 Question "urgency" is malformed');

        $this->client()->systemOne()->state('hello')->noul('urgent')->send();
    }

    #[Test]
    public function it_formats_validation_errors_as_paths(): void
    {
        $this->transport->queue([
            'detail' => [
                ['loc' => ['body', 'questions', 'urgency'], 'msg' => 'field required'],
                ['loc' => ['body', 'state'], 'msg' => 'must not be empty'],
            ],
        ], 422);

        $this->expectExceptionMessage('422 questions.urgency: field required; state: must not be empty');

        $this->client()->systemOne()->state('hello')->noul('urgent')->send();
    }

    #[Test]
    public function an_empty_body_still_produces_a_readable_message(): void
    {
        $this->transport->queue(null, 404);

        $this->expectExceptionMessage('404 status code (no body)');

        $this->client()->systemOne()->state('hello')->noul('urgent')->send();
    }

    #[Test]
    public function a_long_raw_body_is_truncated(): void
    {
        $this->transport->queue(str_repeat('x', 400), 400);

        try {
            $this->client()->systemOne()->state('hello')->noul('urgent')->send();
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame(207, strlen($exception->getMessage()));
            self::assertStringStartsWith('400 xxx', $exception->getMessage());
            self::assertStringEndsWith('…', $exception->getMessage());
        }
    }

    #[Test]
    public function an_api_error_carries_its_headers_and_request_id(): void
    {
        $this->transport->queue(['error' => 'slow down'], 429, [
            'x-typesafe-request-id' => 'req_abc',
            'retry-after' => '12',
        ]);

        try {
            $this->client()->retry(fn ($policy) => $policy->maxRetries(0))
                ->systemOne()->state('hello')->noul('urgent')->send();
            self::fail('Expected a rate limit exception.');
        } catch (RateLimitException $exception) {
            self::assertSame('req_abc', $exception->getRequestId());
            self::assertSame(12.0, $exception->getRetryAfter());
            self::assertSame(['error' => 'slow down'], $exception->getBody());
        }
    }
}
