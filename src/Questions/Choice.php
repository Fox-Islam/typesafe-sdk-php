<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Questions;

use Phox\TypeSafe\Exceptions\TypeSafeException;

/**
 * A question answered with one of several named labels.
 *
 * ```php
 * Choice::ask('What is this ticket about?')
 *     ->option('billing', 'Charges, invoices and refunds')
 *     ->option('technical', 'Something is broken')
 *     ->option('other');
 * ```
 *
 * @see https://docs.typesafe.ai/primitives/choice
 *
 * @phpstan-import-type Content from Question
 */
final class Choice extends Question
{
    /** @var array<string, Content> */
    private array $criteria = [];

    /**
     * @param Content $instructions
     */
    public static function ask(string|array|null $instructions = null): self
    {
        return (new self())->instructions($instructions);
    }

    /**
     * Start from a set of labels, as a list of names or a map of name to description.
     *
     * @param list<string>|array<string, Content> $options
     */
    public static function between(array $options): self
    {
        return (new self())->options($options);
    }

    /**
     * Add one label, with an optional description of when it applies.
     *
     * @param Content $description
     */
    public function option(string $label, string|array|null $description = null): self
    {
        $this->criteria[$label] = $description;

        return $this;
    }

    /**
     * Add several labels, as a list of names or a map of name to description.
     *
     * @param list<string>|array<string, Content> $options
     */
    public function options(array $options): self
    {
        foreach ($options as $label => $description) {
            if (is_int($label)) {
                if (! is_string($description)) {
                    throw new TypeSafeException('Choice options given as a list must be label strings.');
                }

                $this->option($description);
                continue;
            }

            $this->option($label, $description);
        }

        return $this;
    }

    /**
     * @return array<string, Content>
     */
    public function getOptions(): array
    {
        return $this->criteria;
    }

    public function type(): string
    {
        return 'choice';
    }

    public function validate(string $name): void
    {
        if ($this->criteria === []) {
            throw new TypeSafeException("Choice question \"{$name}\" has no options; at least one label is required.");
        }
    }

    protected function payload(): array
    {
        return ['criteria' => $this->criteria];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        // Cast so a map of labels always encodes as a JSON object, never as a list.
        $data['criteria'] = (object) $this->criteria;

        return $data;
    }
}
