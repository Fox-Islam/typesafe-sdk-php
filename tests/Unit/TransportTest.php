<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\TimeoutException;
use Phox\TypeSafe\Http\GuzzleTransport;
use Phox\TypeSafe\Http\Psr18Transport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class TransportTest extends TestCase
{
    private function guzzle(mixed ...$queue): GuzzleTransport
    {
        return new GuzzleTransport(new Guzzle(['handler' => HandlerStack::create(new MockHandler(array_values($queue)))]));
    }

    #[Test]
    public function error_statuses_come_back_as_responses_rather_than_exceptions(): void
    {
        $response = $this->guzzle(new Response(500, [], '{"error":"boom"}'))
            ->send(new Request('GET', 'https://api.typesafe.ai/v1/models'), 5.0);

        self::assertSame(500, $response->getStatusCode());
    }

    #[Test]
    public function a_curl_timeout_becomes_a_timeout_exception(): void
    {
        $request = new Request('GET', 'https://api.typesafe.ai/v1/models');
        $transport = $this->guzzle(new ConnectException('cURL error 28', $request, null, ['errno' => 28]));

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Request timed out after 1.5s.');

        $transport->send($request, 1.5);
    }

    #[Test]
    public function other_connection_failures_become_connection_exceptions(): void
    {
        $request = new Request('GET', 'https://api.typesafe.ai/v1/models');
        $transport = $this->guzzle(new ConnectException('Could not resolve host', $request, null, ['errno' => 6]));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection error: Could not resolve host');

        $transport->send($request, 5.0);
    }

    #[Test]
    public function a_timeout_exception_is_a_connection_exception(): void
    {
        self::assertInstanceOf(ConnectionException::class, new TimeoutException(3.0));
        self::assertSame(3.0, (new TimeoutException(3.0))->getTimeout());
    }

    #[Test]
    public function a_psr18_client_can_be_used_instead(): void
    {
        $client = new class implements ClientInterface {
            public ?RequestInterface $seen = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new Response(200, [], '{"models":[]}');
            }
        };

        $response = (new Psr18Transport($client))->send(new Request('GET', 'https://api.typesafe.ai/v1/models'), 5.0);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://api.typesafe.ai/v1/models', (string) $client->seen?->getUri());
    }

    #[Test]
    public function a_psr18_failure_becomes_a_connection_exception(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('socket closed') extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Connection error: socket closed');

        (new Psr18Transport($client))->send(new Request('GET', 'https://api.typesafe.ai/v1/models'), 5.0);
    }
}
