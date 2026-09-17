<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Support\Headers;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use Phox\TypeSafe\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;

final class LoggingTest extends ClientTestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $this->transport->queue(['model' => 'jev-latest', 'usage' => [], 'answers' => []], headers: ['x-typesafe-request-id' => 'req_1']);
    }

    #[Test]
    public function nothing_is_logged_by_default(): void
    {
        $this->client()->logger($this->logger)->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function info_logs_one_line_per_attempt(): void
    {
        $this->client()->logger($this->logger)->logLevel(LogLevel::Info)
            ->systemOne()->state('hello')->noul('urgent')->send();

        $messages = $this->logger->messages('info');

        self::assertCount(1, $messages);
        self::assertMatchesRegularExpression('/^\[typesafe-sdk\] #\d+ POST \/v1\/systemone <- 200 in \d+ms \(request req_1\)$/', $messages[0]);
    }

    #[Test]
    public function debug_adds_headers_and_bodies(): void
    {
        $this->client()->logger($this->logger)->logLevel('debug')
            ->systemOne()->state('hello')->noul('urgent')->send();

        $debug = array_values(array_filter($this->logger->records, static fn (array $record): bool => $record['level'] === 'debug'));

        self::assertCount(2, $debug);
        self::assertArrayHasKey('headers', $debug[0]['context']);
        self::assertSame(['state' => 'hello', 'model' => 'jev-latest', 'questions' => ['urgent' => ['type' => 'noul', 'instructions' => null]]], $debug[0]['context']['body']);
    }

    #[Test]
    public function the_api_key_is_redacted_in_debug_logs(): void
    {
        $this->client()->apiKey('sk-live-01234567890abcdef')->logger($this->logger)->logLevel('debug')
            ->systemOne()->state('hello')->noul('urgent')->send();

        $headers = $this->logger->records[0]['context']['headers'];

        self::assertIsArray($headers);
        self::assertSame('Bearer ***cdef', $headers['Authorization']);
        self::assertStringNotContainsString('sk-live', print_r($this->logger->records, true));
    }

    #[Test]
    public function off_silences_every_level(): void
    {
        $this->client()->logger($this->logger)->logLevel(LogLevel::Off)
            ->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function short_secrets_are_masked_without_a_suffix(): void
    {
        self::assertSame(['Authorization' => 'Bearer ***'], Headers::redact(['Authorization' => 'Bearer short']));
        self::assertSame(['X-Api-Key' => '***cdef'], Headers::redact(['X-Api-Key' => '01234567890abcdef']));
        self::assertSame(['Cookie' => '***'], Headers::redact(['Cookie' => 'session=abc']));
        self::assertSame(['X-Tenant' => 'acme'], Headers::redact(['X-Tenant' => 'acme']));
    }
}
