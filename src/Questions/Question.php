<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Questions;

use JsonSerializable;

/**
 * A question put to the model, identified by its `type` on the wire.
 *
 * Instructions, and every criterion description, may be a string, a JSON-ready
 * array, or `null` to leave the outcome undescribed.
 *
 * @see Noul   yes/no
 * @see Choice one of several named labels
 * @see Score  a level on an ordered rubric
 *
 * @phpstan-type Content string|array<array-key, mixed>|null
 */
abstract class Question implements JsonSerializable
{
    /** @var Content */
    protected string|array|null $instructions = null;

    /**
     * Set the question itself: text, a JSON-ready array, or `null`.
     *
     * @param Content $instructions
     */
    public function instructions(string|array|null $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * @return Content
     */
    public function getInstructions(): string|array|null
    {
        return $this->instructions;
    }

    /** The discriminator the API uses to pick an answer shape. */
    abstract public function type(): string;

    /**
     * Reject a question the API would refuse, naming it as the caller keyed it.
     *
     * @throws \Phox\TypeSafe\Exceptions\TypeSafeException
     */
    abstract public function validate(string $name): void;

    /**
     * The criteria half of the request body.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type(), 'instructions' => $this->instructions] + $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
