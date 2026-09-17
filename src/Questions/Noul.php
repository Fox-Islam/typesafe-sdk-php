<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Questions;

/**
 * A yes/no question, answered with the probability of yes.
 *
 * ```php
 * Noul::ask('Is the customer asking for a refund?')
 *     ->yes('They want money back')
 *     ->no('They want something else');
 * ```
 *
 * @see https://docs.typesafe.ai/primitives/noul
 *
 * @phpstan-import-type Content from Question
 */
final class Noul extends Question
{
    /** @var Content */
    private string|array|null $yes = null;

    /** @var Content */
    private string|array|null $no = null;

    private bool $described = false;

    /**
     * @param Content $instructions
     */
    public static function ask(string|array|null $instructions = null): self
    {
        return (new self())->instructions($instructions);
    }

    /**
     * Describe what a yes answer means.
     *
     * @param Content $description
     */
    public function yes(string|array|null $description): self
    {
        $this->yes = $description;
        $this->described = true;

        return $this;
    }

    /**
     * Describe what a no answer means.
     *
     * @param Content $description
     */
    public function no(string|array|null $description): self
    {
        $this->no = $description;
        $this->described = true;

        return $this;
    }

    /**
     * Describe both outcomes at once, keyed `true` and `false`.
     *
     * @param array{true?: Content, false?: Content}|null $criteria
     */
    public function criteria(?array $criteria): self
    {
        if ($criteria === null) {
            $this->yes = $this->no = null;
            $this->described = false;

            return $this;
        }

        return $this
            ->yes($criteria['true'] ?? null)
            ->no($criteria['false'] ?? null);
    }

    public function type(): string
    {
        return 'noul';
    }

    public function validate(string $name): void
    {
        // Both outcomes are optional: a noul question with no criteria is valid.
    }

    protected function payload(): array
    {
        if (! $this->described) {
            return [];
        }

        return ['criteria' => ['true' => $this->yes, 'false' => $this->no]];
    }
}
