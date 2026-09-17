<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

/**
 * The label the model selected, with its probability across every label.
 *
 * @see https://docs.typesafe.ai/primitives/choice
 */
final class ChoiceAnswer extends Answer
{
    public function type(): string
    {
        return 'choice';
    }

    /** The selected label. */
    public function choice(): string
    {
        $choice = $this->data['choice'] ?? null;

        return is_string($choice) ? $choice : '';
    }

    /** Reported confidence in the selected label, from zero to one. */
    public function confidence(): float
    {
        return $this->float('confidence');
    }

    /**
     * Probabilities keyed by label.
     *
     * @return array<string, float>
     */
    public function probabilities(): array
    {
        /** @var array<string, float> */
        return $this->floatMap('probabilities');
    }

    /** The probability of one label, or `null` when the model did not report it. */
    public function probabilityOf(string $label): ?float
    {
        return $this->probabilities()[$label] ?? null;
    }

    /** Whether the model picked `$label`. */
    public function is(string $label): bool
    {
        return $this->choice() === $label;
    }
}
