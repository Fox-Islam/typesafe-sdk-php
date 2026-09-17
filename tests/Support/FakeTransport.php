<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Support\Json;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Replays queued responses and records what was sent, so tests can assert on
 * requests without touching the network.
 */
final class FakeTransport implements Transport
{
    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<float> */
    public array $timeouts = [];

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function queue(array|string|null $body = null, int $status = 200, array $headers = []): self
    {
        $this->queue[] = new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            $body === null ? '' : (is_string($body) ? $body : Json::encode($body)),
        );

        return $this;
    }

    public function queueFailure(Throwable $failure): self
    {
        $this->queue[] = $failure;

        return $this;
    }

    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        $this->requests[] = $request;
        $this->timeouts[] = $timeout;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \RuntimeException('FakeTransport ran out of queued responses.');
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastBody(): array
    {
        $body = json_decode((string) $this->lastRequest()->getBody(), true);

        return is_array($body) ? $body : [];
    }

    public function callCount(): int
    {
        return count($this->requests);
    }
}
