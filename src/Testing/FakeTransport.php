<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Testing;

use GuzzleHttp\Psr7\Response;
use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Support\Json;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A transport that never reaches the network: it replays what you queued and
 * records what was sent.
 *
 * This is the raw layer, where a reply is an HTTP status and a body. Reach for
 * {@see FakeTypeSafe} instead to script answers rather than payloads.
 *
 * ```php
 * $transport = (new FakeTransport())->queue(['model' => 'jev-latest', 'answers' => []]);
 *
 * Client::make('fake-api-key')->transport($transport);
 * ```
 */
final class FakeTransport implements Transport
{
    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    /** @var (callable(RequestInterface, float): (ResponseInterface|Throwable))|null */
    private $resolver = null;

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<float> */
    public array $timeouts = [];

    /**
     * Queue one response. An array body is encoded as JSON; a string is sent as-is.
     *
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

    /**
     * Queue a request that never completes, such as a
     * {@see \Phox\TypeSafe\Exceptions\TimeoutException}.
     */
    public function queueFailure(Throwable $failure): self
    {
        $this->queue[] = $failure;

        return $this;
    }

    /**
     * Answer whatever the queue does not cover, instead of running out.
     *
     * @param (callable(RequestInterface, float): (ResponseInterface|Throwable))|null $resolver
     */
    public function resolveWith(?callable $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }

    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        $this->requests[] = $request;
        $this->timeouts[] = $timeout;

        $next = array_shift($this->queue) ?? ($this->resolver === null ? null : ($this->resolver)($request, $timeout));

        if ($next === null) {
            throw new RuntimeException(sprintf(
                'FakeTransport has no queued response for %s %s.',
                $request->getMethod(),
                $request->getUri()->getPath(),
            ));
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)]
            ?? throw new RuntimeException('FakeTransport has not been sent a request.');
    }

    /**
     * The last request body, decoded.
     *
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

    /** Forget every recorded request and anything still queued. */
    public function reset(): self
    {
        $this->queue = [];
        $this->requests = [];
        $this->timeouts = [];

        return $this;
    }
}
