<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Testing;

use Phox\TypeSafe\Support\Headers;
use Phox\TypeSafe\Support\Json;
use Phox\TypeSafe\TypeSafe;
use Psr\Http\Message\RequestInterface;

/**
 * One request a faked client made, decoded so a test can assert on what was
 * asked rather than on JSON.
 *
 * ```php
 * $call = $fake->lastCall();
 *
 * self::assertSame('I was charged twice.', $call->state());
 * self::assertTrue($call->asked('category'));
 * ```
 */
final class FakeCall
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly int $number,
        private readonly RequestInterface $request,
        private readonly float $timeout,
        private readonly array $body,
        private readonly array $headers,
    ) {}

    public static function fromRequest(RequestInterface $request, int $number, float $timeout): self
    {
        $body = Json::decode((string) $request->getBody());

        return new self(
            $number,
            $request,
            $timeout,
            is_array($body) ? $body : [],
            Headers::flatten($request->getHeaders()),
        );
    }

    /** Where this call came in the run, counting from one. */
    public function number(): int
    {
        return $this->number;
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    public function path(): string
    {
        return $this->request->getUri()->getPath();
    }

    /** Whether this is a System One call, on either provider's path. */
    public function isSystemOne(): bool
    {
        return in_array($this->path(), [TypeSafe::SYSTEM_ONE_PATH, TypeSafe::OPENROUTER_DECISIONS_PATH], true);
    }

    public function isModelList(): bool
    {
        return $this->path() === TypeSafe::MODELS_PATH;
    }

    /** The timeout the client allowed this attempt, in seconds. */
    public function timeout(): float
    {
        return $this->timeout;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return Headers::get($this->headers, $name);
    }

    /** Which retry this attempt was; zero for the first try. */
    public function retryCount(): int
    {
        return (int) ($this->header(TypeSafe::RETRY_COUNT_HEADER) ?? 0);
    }

    /**
     * The decoded request body.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * What the questions were asked about.
     *
     * @return string|array<array-key, mixed>|null
     */
    public function state(): string|array|null
    {
        $state = $this->body['state'] ?? null;

        return is_string($state) || is_array($state) ? $state : null;
    }

    /** The model the call resolved to, or `null` on a request that names none. */
    public function model(): ?string
    {
        return is_string($this->body['model'] ?? null) ? $this->body['model'] : null;
    }

    /**
     * The questions as they were sent, keyed by answer name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function questions(): array
    {
        $questions = $this->body['questions'] ?? null;

        if (! is_array($questions)) {
            return [];
        }

        $keyed = [];
        foreach ($questions as $name => $question) {
            if (is_array($question)) {
                $keyed[(string) $name] = $question;
            }
        }

        return $keyed;
    }

    /**
     * One question as it was sent, or `null` when it was not asked.
     *
     * @return array<string, mixed>|null
     */
    public function question(string $name): ?array
    {
        return $this->questions()[$name] ?? null;
    }

    public function asked(string $name): bool
    {
        return $this->question($name) !== null;
    }

    /** The question type sent under a name: `noul`, `choice`, `score`, or `null`. */
    public function questionType(string $name): ?string
    {
        $type = $this->question($name)['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    public function request(): RequestInterface
    {
        return $this->request;
    }
}
