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
     * Which of the two is decided by the whole array, not by each key. PHP casts
     * a numeric string key to an integer, so `['30' => 'Thirty days']` arrives
     * with an integer key and reads as a list entry; taking the value as the
     * label then ships the description as the option name and loses `30`.
     * `array_is_list()` tells the two apart: a list runs 0, 1, 2 with nothing
     * missing, and a map keyed `30` does not.
     *
     * @param list<string>|array<array-key, Content> $options
     */
    public function options(array $options): self
    {
        if (array_is_list($options)) {
            foreach ($options as $label) {
                if (! is_string($label)) {
                    throw new TypeSafeException('Choice options given as a list must be label strings.');
                }

                $this->option($label);
            }

            return $this;
        }

        foreach ($options as $label => $description) {
            $this->option((string) $label, $description);
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
        // Always an object. A Choice keyed `0`, `1`, `2` is a PHP list once the
        // numeric-string keys have been cast, and `json_encode` writes a list as
        // a JSON array - which the endpoint reads as options with no labels.
        return ['criteria' => (object) $this->criteria];
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
