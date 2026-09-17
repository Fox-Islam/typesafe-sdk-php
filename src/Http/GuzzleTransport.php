<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Http;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\RequestOptions;
use Phox\TypeSafe\Contracts\Transport;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\TimeoutException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The default transport: Guzzle, with the timeout applied per attempt.
 */
final class GuzzleTransport implements Transport
{
    /** cURL's CURLE_OPERATION_TIMEDOUT, the one Guzzle surfaces for an elapsed timeout. */
    private const int CURL_TIMEOUT = 28;

    private readonly ClientInterface $guzzle;

    public function __construct(?ClientInterface $guzzle = null)
    {
        $this->guzzle = $guzzle ?? new Guzzle();
    }

    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        try {
            return $this->guzzle->send($request, [
                // Statuses are the retry loop's business, so let every response through.
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::CONNECT_TIMEOUT => $timeout,
            ]);
        } catch (ConnectException $exception) {
            throw self::timedOut($exception)
                ? new TimeoutException($timeout, $exception)
                : new ConnectionException("Connection error: {$exception->getMessage()}", $exception);
        } catch (TransferException $exception) {
            throw new ConnectionException("Connection error: {$exception->getMessage()}", $exception);
        }
    }

    private static function timedOut(ConnectException $exception): bool
    {
        $errno = $exception->getHandlerContext()['errno'] ?? null;

        if ($errno === self::CURL_TIMEOUT) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'timed out');
    }
}
