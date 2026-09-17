<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

/**
 * A yes/no answer.
 *
 * @see https://docs.typesafe.ai/primitives/noul
 */
final class NoulAnswer extends Answer
{
    public function type(): string
    {
        return 'noul';
    }

    /** Probability of a yes answer, from zero to one. */
    public function noul(): float
    {
        return $this->float('noul');
    }

    /** Whether the probability of yes clears `$threshold`. */
    public function isYes(float $threshold = 0.5): bool
    {
        return $this->noul() >= $threshold;
    }

    /** Whether the probability of yes falls below `$threshold`. */
    public function isNo(float $threshold = 0.5): bool
    {
        return ! $this->isYes($threshold);
    }
}
