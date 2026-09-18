<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Requests;

use Phox\TypeSafe\Config;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Http\Requester;
use Phox\TypeSafe\Http\RequestOptions;
use Phox\TypeSafe\Responses\ModelCard;
use Phox\TypeSafe\Responses\ModelList;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\TypeSafe;

/**
 * The models available to the account.
 *
 * ```php
 * $client->models()->list()->names();
 * ```
 */
final class Models
{
    private ?float $timeout = null;

    /** @var array<string, string> */
    private array $headers = [];

    private ?RetryPolicy $retry = null;

    public function __construct(
        private readonly Requester $requester,
        private readonly Config $config,
    ) {}

    /**
     * Override the per-attempt timeout, in seconds, for this call only.
     */
    public function timeout(float $seconds): self
    {
        $this->timeout = Config::assertTimeout($seconds);

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * @param RetryPolicy|(callable(RetryPolicy): RetryPolicy) $retry
     */
    public function retry(RetryPolicy|callable $retry): self
    {
        $this->retry = $retry instanceof RetryPolicy ? $retry : $retry($this->config->getRetry());

        return $this;
    }

    /**
     * @throws TypeSafeException The provider has no model catalogue.
     * @throws \Phox\TypeSafe\Exceptions\ApiException The API rejected the request.
     * @throws \Phox\TypeSafe\Exceptions\ConnectionException The request never completed.
     */
    public function list(): ModelList
    {
        $provider = $this->config->getProvider();

        if (! $provider->listsModels()) {
            throw new TypeSafeException(sprintf(
                'The %s provider does not publish a model catalogue; name a model directly, e.g. %s.',
                $provider->value,
                TypeSafe::OPENROUTER_MODEL_LATEST,
            ));
        }

        $response = $this->requester->send(
            'GET',
            TypeSafe::MODELS_PATH,
            null,
            new RequestOptions($this->timeout, $this->headers, $this->retry),
        );

        return ModelList::fromArray($response->data(), $response->requestId());
    }

    /**
     * Look one model up by name, or `null` when the account cannot use it.
     */
    public function find(string $name): ?ModelCard
    {
        return $this->list()->find($name);
    }
}
