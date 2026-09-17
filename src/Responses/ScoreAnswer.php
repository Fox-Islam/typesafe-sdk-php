<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

/**
 * An expected score with the rubric it was drawn from.
 *
 * @see https://docs.typesafe.ai/primitives/score
 *
 * @phpstan-import-type Content from \Phox\TypeSafe\Questions\Question
 */
final class ScoreAnswer extends Answer
{
    public function type(): string
    {
        return 'score';
    }

    /**
     * Expected score, which may fall between the integer rubric levels.
     */
    public function score(): float
    {
        return $this->float('score');
    }

    /** The nearest rubric level to the expected score. */
    public function nearestLevel(): int
    {
        return (int) round($this->score());
    }

    /** Reported confidence in the score, from zero to one. */
    public function confidence(): float
    {
        return $this->float('confidence');
    }

    /**
     * Rubric descriptions keyed by integer score.
     *
     * @return array<int, Content>
     */
    public function legend(): array
    {
        $legend = $this->data['legend'] ?? [];

        if (! is_array($legend)) {
            return [];
        }

        $keyed = [];
        foreach ($legend as $score => $description) {
            $keyed[(int) $score] = $description;
        }

        return $keyed;
    }

    /**
     * The rubric description for one level, or for the nearest level by default.
     *
     * @return Content
     */
    public function describe(?int $level = null): string|array|null
    {
        return $this->legend()[$level ?? $this->nearestLevel()] ?? null;
    }

    /**
     * Probabilities keyed by integer score.
     *
     * @return array<int, float>
     */
    public function probabilities(): array
    {
        $keyed = [];
        foreach ($this->floatMap('probabilities') as $score => $probability) {
            $keyed[(int) $score] = $probability;
        }

        return $keyed;
    }

    /** The probability of one level, or `null` when the model did not report it. */
    public function probabilityOf(int $level): ?float
    {
        return $this->probabilities()[$level] ?? null;
    }
}
