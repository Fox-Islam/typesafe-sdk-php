<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Questions;

use JsonSerializable;
use Phox\TypeSafe\Assets\Image;

/**
 * A question put to the model, identified by its `type` on the wire.
 *
 * Instructions, and every criterion description, may be a string, an
 * {@see Image}, a JSON-ready array, or `null` to leave the outcome undescribed.
 * An image may also be interpolated into a string or nested in an array.
 *
 * @see Noul   yes/no
 * @see Choice one of several named labels
 * @see Score  a level on an ordered rubric
 * @see Box    where something is in an image
 *
 * @phpstan-type Content string|Image|array<array-key, mixed>|null
 */
abstract class Question implements JsonSerializable
{
    /** @var Content */
    protected string|array|Image|null $instructions = null;

    /**
     * Set the question itself: text, an image, a JSON-ready array, or `null`.
     *
     * @param Content $instructions
     */
    public function instructions(string|array|Image|null $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * @return Content
     */
    public function getInstructions(): string|array|Image|null
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
