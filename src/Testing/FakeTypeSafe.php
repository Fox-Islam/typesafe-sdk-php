<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Testing;

use GuzzleHttp\Psr7\Response;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Support\Json;
use Phox\TypeSafe\TypeSafe;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A client that answers without calling the API, so tests that depend on Jev
 * are as repeatable as the rest of the suite.
 *
 * ```php
 * $fake = new FakeTypeSafe();
 *
 * $fake->reply(FakeAnswers::make()->choice('category', 'billing')->noul('urgent', true));
 *
 * $tickets = new TicketRouter($fake->client());
 * $tickets->route('I was charged twice.');
 *
 * self::assertSame('I was charged twice.', $fake->lastCall()->state());
 * ```
 *
 * Nothing has to be scripted: an unscripted call is answered by
 * {@see SimulatedAnswer}, which reads the questions off the request and answers
 * every one of them in the right shape, the same way every run. Script only
 * what a test asserts on.
 *
 * Replies queued with {@see reply()}, {@see fail()} and {@see throw()} are
 * served one per call, in order. {@see alwaysReply()} covers System One calls
 * once the queue is empty; a model list with nothing queued returns the
 * catalogue from {@see models()}.
 */
final class FakeTypeSafe
{
    /** The key {@see client()} is built with, so no environment is needed. */
    public const string API_KEY = 'fake-api-key';

    /** The release date on simulated model cards. */
    public const string RELEASE_DATE = '2026-01-01';

    private readonly FakeTransport $transport;

    private ?Client $client = null;

    /** @var list<FakeCall> */
    private array $calls = [];

    /** @var list<callable(FakeCall): (ResponseInterface|Throwable)> */
    private array $queue = [];

    /** @var (callable(FakeCall): (ResponseInterface|Throwable))|null */
    private $always = null;

    /** @var array<string, string|null>|null */
    private ?array $catalogue = null;

    public function __construct()
    {
        $this->transport = (new FakeTransport())->resolveWith(
            fn (RequestInterface $request, float $timeout): ResponseInterface|Throwable => $this->respond($request, $timeout),
        );
    }

    public static function make(): self
    {
        return new self();
    }

    /**
     * A client wired to this fake: no API key needed, no network, and retries
     * off so a queued failure surfaces as itself rather than being retried.
     */
    public function client(): Client
    {
        return $this->client ??= $this->bind(Client::make(self::API_KEY));
    }

    /**
     * Point an existing client at this fake — the one an application already
     * resolved from its container, for instance — and hand it back.
     *
     * Retries come off with it, so one queued failure is one failed call. A
     * client configured for production retries 408s, 429s and 5xx, which in a
     * test means a queued failure is swallowed and the reply queued behind it
     * is spent answering the retry — a test that then fails somewhere else
     * entirely. Pass `retries: true` to keep the client's own policy, which is
     * what a test of retrying wants; {@see FakeCall::retryCount()} says which
     * attempt each call was.
     */
    public function bind(Client $client, bool $retries = false): Client
    {
        if (! $retries) {
            $client->retry(RetryPolicy::none());
        }

        return $client->transport($this->transport);
    }

    /** The transport underneath, for raw status codes and bodies. */
    public function transport(): FakeTransport
    {
        return $this->transport;
    }

    /**
     * Answer the next call, or the next `$times` calls.
     *
     * @param FakeAnswers|array<string, mixed>|(callable(FakeCall): (FakeAnswers|array<string, mixed>)) $reply
     */
    public function reply(FakeAnswers|array|callable $reply, int $times = 1): self
    {
        return $this->push($this->responder($reply), $times);
    }

    /**
     * Answer every System One call the queue does not cover. Pass `null` to go
     * back to simulated answers.
     *
     * @param FakeAnswers|array<string, mixed>|(callable(FakeCall): (FakeAnswers|array<string, mixed>))|null $reply
     */
    public function alwaysReply(FakeAnswers|array|callable|null $reply): self
    {
        $this->always = $reply === null ? null : $this->responder($reply);

        return $this;
    }

    /**
     * Fail the next call with an HTTP status, so a test can exercise the
     * {@see \Phox\TypeSafe\Exceptions\ApiException} it raises.
     *
     * Retries are off on {@see client()}; on a client that retries, queue as
     * many failures as the policy will attempt.
     *
     * @param mixed $body Defaults to an error body carrying a readable message.
     * @param array<string, string> $headers
     */
    public function fail(int $status, mixed $body = null, array $headers = [], int $times = 1): self
    {
        $body ??= ['error' => ['message' => "Simulated {$status} response from FakeTypeSafe."]];

        return $this->push(
            fn (FakeCall $call): ResponseInterface => $this->response($body, $status, $call, $headers),
            $times,
        );
    }

    /**
     * Fail the next call with an exception, such as a
     * {@see \Phox\TypeSafe\Exceptions\TimeoutException}.
     */
    public function throw(Throwable $exception, int $times = 1): self
    {
        return $this->push(static fn (FakeCall $call): Throwable => $exception, $times);
    }

    /**
     * The catalogue `models()->list()` comes back with, as a list of names or a
     * map of name to description.
     *
     * @param list<string>|array<string, string|null> $models
     */
    public function models(array $models): self
    {
        $catalogue = [];

        foreach ($models as $name => $description) {
            if (is_int($name)) {
                $catalogue[(string) $description] = null;
                continue;
            }

            $catalogue[$name] = $description;
        }

        $this->catalogue = $catalogue;

        return $this;
    }

    /**
     * Every call the faked client made, in order.
     *
     * @return list<FakeCall>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The System One calls, leaving out anything else the client sent.
     *
     * @return list<FakeCall>
     */
    public function systemOneCalls(): array
    {
        return array_values(array_filter($this->calls, static fn (FakeCall $call): bool => $call->isSystemOne()));
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** One call by position, counting from zero. */
    public function call(int $index): ?FakeCall
    {
        return $this->calls[$index] ?? null;
    }

    /**
     * @throws TypeSafeException The faked client has not been called.
     */
    public function lastCall(): FakeCall
    {
        return $this->calls[array_key_last($this->calls)]
            ?? throw new TypeSafeException('The faked client has not been called yet.');
    }

    /** Whether any call asked a question under this name. */
    public function asked(string $name): bool
    {
        foreach ($this->calls as $call) {
            if ($call->asked($name)) {
                return true;
            }
        }

        return false;
    }

    /** Forget what was recorded and anything still queued; keeps the client. */
    public function reset(): self
    {
        $this->calls = [];
        $this->queue = [];
        $this->always = null;
        $this->catalogue = null;
        $this->transport->reset();

        return $this;
    }

    private function respond(RequestInterface $request, float $timeout): ResponseInterface|Throwable
    {
        $call = FakeCall::fromRequest($request, count($this->calls) + 1, $timeout);
        $this->calls[] = $call;

        $reply = array_shift($this->queue)
            ?? ($call->isModelList()
                ? fn (FakeCall $modelList): ResponseInterface => $this->response($this->catalogueBody(), 200, $modelList)
                : $this->always ?? $this->responder(FakeAnswers::make()));

        return $reply($call);
    }

    /**
     * @param FakeAnswers|array<string, mixed>|(callable(FakeCall): (FakeAnswers|array<string, mixed>)) $reply
     * @return callable(FakeCall): ResponseInterface
     */
    private function responder(FakeAnswers|array|callable $reply): callable
    {
        return function (FakeCall $call) use ($reply): ResponseInterface {
            $body = $reply instanceof FakeAnswers || is_array($reply) ? $reply : $reply($call);

            return $this->response(
                $body instanceof FakeAnswers ? FakeAnswers::toWire($body->toArray($call)) : $body,
                200,
                $call,
            );
        };
    }

    /**
     * @param callable(FakeCall): (ResponseInterface|Throwable) $reply
     */
    private function push(callable $reply, int $times): self
    {
        for ($i = 0; $i < max(1, $times); $i++) {
            $this->queue[] = $reply;
        }

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    private function response(mixed $body, int $status, FakeCall $call, array $headers = []): ResponseInterface
    {
        $requestId = 'fake-request-' . $call->number();

        return new Response(
            $status,
            $headers + [
                'Content-Type' => 'application/json',
                TypeSafe::REQUEST_ID_HEADER => $requestId,
                TypeSafe::GENERATION_ID_HEADER => $requestId,
            ],
            is_string($body) ? $body : Json::encode($body),
        );
    }

    /**
     * @return array{models: list<array<string, mixed>>}
     */
    private function catalogueBody(): array
    {
        $catalogue = $this->catalogue ?? [TypeSafe::DEFAULT_MODEL => 'Simulated by FakeTypeSafe.'];

        $cards = [];
        foreach ($catalogue as $name => $description) {
            $cards[] = [
                'name' => $name,
                'description' => $description ?? 'Simulated by FakeTypeSafe.',
                'release_date' => self::RELEASE_DATE,
            ];
        }

        return ['models' => $cards];
    }
}
