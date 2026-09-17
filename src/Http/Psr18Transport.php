<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Http;

use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adapts any PSR-18 client.
 *
 * PSR-18 has no per-request timeout, so the SDK's timeout is not enforced here:
 * configure one on the client you pass in.
 */
final class Psr18Transport implements Transport
{
    public function __construct(private readonly ClientInterface $client) {}

    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new ConnectionException("Connection error: {$exception->getMessage()}", $exception);
        }
    }
}
