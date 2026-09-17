<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Contracts;

use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\TimeoutException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one HTTP request. Retries, logging and error mapping happen above this;
 * an implementation only has to deliver the request or explain why it could not.
 *
 * Implement this to swap in another HTTP stack, or to stub the network in tests.
 */
interface Transport
{
    /**
     * Send a request and return the response, whatever its status code.
     *
     * @param float $timeout Seconds allowed for this attempt.
     *
     * @throws TimeoutException The attempt exceeded `$timeout`.
     * @throws ConnectionException The request never produced a complete response.
     */
    public function send(RequestInterface $request, float $timeout): ResponseInterface;
}
