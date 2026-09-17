<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The models available to the account.
 *
 * @implements IteratorAggregate<int, ModelCard>
 */
final class ModelList implements Countable, IteratorAggregate
{
    /**
     * @param list<ModelCard> $models
     */
    private function __construct(
        private readonly array $models,
        private readonly ?string $requestId,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $requestId = null): self
    {
        $models = is_array($data['models'] ?? null) ? $data['models'] : [];

        return new self(
            array_values(array_map(
                static fn (mixed $model): ModelCard => ModelCard::fromArray(is_array($model) ? $model : []),
                $models,
            )),
            $requestId,
        );
    }

    /**
     * @return list<ModelCard>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ModelCard $model): string => $model->name(), $this->models);
    }

    public function find(string $name): ?ModelCard
    {
        foreach ($this->models as $model) {
            if ($model->name() === $name) {
                return $model;
            }
        }

        return null;
    }

    public function first(): ?ModelCard
    {
        return $this->models[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->models === [];
    }

    public function count(): int
    {
        return count($this->models);
    }

    /** Request ID from `x-typesafe-request-id`, when the API sent one. */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->models);
    }
}
