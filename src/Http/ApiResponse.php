<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Http;

use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Support\Headers;
use Phox\TypeSafe\TypeSafe;
use Psr\Http\Message\ResponseInterface;

/**
 * A successful response with its body already decoded, handed to the callers
 * that turn it into typed objects.
 */
final class ApiResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly mixed $body,
        private readonly ResponseInterface $response,
    ) {}

    public function status(): int
    {
        return $this->status;
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

    /** Request ID from `x-typesafe-request-id`, when the API sent one. */
    public function requestId(): ?string
    {
        return $this->header(TypeSafe::REQUEST_ID_HEADER);
    }

    /** Decoded JSON, the raw response text, or `null` for an empty body. */
    public function body(): mixed
    {
        return $this->body;
    }

    /**
     * The body as a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws TypeSafeException The API did not return a JSON object.
     */
    public function data(): array
    {
        if (! is_array($this->body)) {
            throw new TypeSafeException(sprintf(
                'Expected a JSON object from the API, got %s.',
                $this->body === null ? 'an empty body' : get_debug_type($this->body),
            ));
        }

        return $this->body;
    }

    /** The underlying PSR-7 response, with its body already read. */
    public function raw(): ResponseInterface
    {
        return $this->response;
    }
}
