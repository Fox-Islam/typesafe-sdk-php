<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Exceptions;

use Phox\TypeSafe\Support\Headers;
use Phox\TypeSafe\TypeSafe;

/**
 * A non-2xx response from the API. Subclasses name the common status codes.
 */
class ApiException extends TypeSafeException
{
    /**
     * @param array<string, string> $headers
     * @param mixed $body Decoded JSON, the raw response text, or `null` for an empty body.
     */
    public function __construct(
        private readonly int $status,
        private readonly mixed $body,
        private readonly array $headers = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? self::describe($status, $body), $status);
    }

    /** HTTP status code of the response. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Decoded JSON, the raw response text, or `null` for an empty body. */
    public function getBody(): mixed
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return Headers::get($this->headers, $name);
    }

    /** Request ID from `x-typesafe-request-id`, when the API sent one. */
    public function getRequestId(): ?string
    {
        return $this->getHeader(TypeSafe::REQUEST_ID_HEADER);
    }

    /**
     * Build the exception class that matches a status code.
     *
     * @param array<string, string> $headers
     */
    public static function fromResponse(int $status, mixed $body, array $headers = []): self
    {
        return match (true) {
            $status === 400 => new BadRequestException($status, $body, $headers),
            $status === 401 => new AuthenticationException($status, $body, $headers),
            $status === 403 => new PermissionDeniedException($status, $body, $headers),
            $status === 404 => new NotFoundException($status, $body, $headers),
            $status === 422 => new UnprocessableEntityException($status, $body, $headers),
            $status === 429 => new RateLimitException($status, $body, $headers),
            $status >= 500 => new InternalServerException($status, $body, $headers),
            default => new self($status, $body, $headers),
        };
    }

    private static function describe(int $status, mixed $body): string
    {
        $detail = self::extractMessage($body);
        if ($detail !== null) {
            return "{$status} " . self::truncate($detail);
        }

        if ($body === null) {
            return "{$status} status code (no body)";
        }

        return "{$status} " . self::truncate(is_string($body) ? $body : (json_encode($body) ?: ''));
    }

    /**
     * Keep an error message readable when the body is an HTML page or a dump.
     */
    private static function truncate(string $text): string
    {
        if (strlen($text) <= TypeSafe::MAX_ERROR_BODY_LENGTH) {
            return $text;
        }

        $cut = substr($text, 0, TypeSafe::MAX_ERROR_BODY_LENGTH);

        // Drop a multi-byte sequence the cut left incomplete, so the message stays valid UTF-8.
        return (preg_replace('/[\xC0-\xFF][\x80-\xBF]{0,3}$/', '', $cut) ?? $cut) . '…';
    }

    /**
     * Pull a human-readable message out of a text, error, or validation response body.
     */
    private static function extractMessage(mixed $body): ?string
    {
        if (is_string($body)) {
            return $body === '' ? null : $body;
        }

        if (! is_array($body)) {
            return null;
        }

        $error = $body['error'] ?? null;
        if (is_string($error) && $error !== '') {
            return $error;
        }
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        if (is_string($body['message'] ?? null)) {
            return $body['message'];
        }

        $detail = $body['detail'] ?? null;
        if (is_string($detail) && $detail !== '') {
            return $detail;
        }
        if (is_array($detail) && is_string($detail['message'] ?? null)) {
            return $detail['message'];
        }
        if (is_array($detail) && array_is_list($detail)) {
            return self::describeValidationErrors($detail);
        }

        return null;
    }

    /**
     * Format FastAPI-style validation errors as `path: message` entries.
     *
     * @param list<mixed> $errors
     */
    private static function describeValidationErrors(array $errors): ?string
    {
        $parts = [];

        foreach ($errors as $error) {
            if (! is_array($error) || ! is_string($error['msg'] ?? null)) {
                continue;
            }

            $location = $error['loc'] ?? null;
            $path = is_array($location)
                ? implode('.', array_filter($location, static fn (mixed $segment): bool => is_scalar($segment) && $segment !== 'body'))
                : '';

            $parts[] = $path === '' ? $error['msg'] : "{$path}: {$error['msg']}";
        }

        return $parts === [] ? null : implode('; ', $parts);
    }
}
