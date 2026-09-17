<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Questions;

use Phox\TypeSafe\Exceptions\TypeSafeException;

/**
 * A question answered with a level on an ordered rubric, scored from zero.
 *
 * ```php
 * Score::ask('How urgent is this ticket?')
 *     ->level('Can wait until next week')
 *     ->level('Should be handled today')
 *     ->level('The customer is blocked right now');
 * ```
 *
 * @see https://docs.typesafe.ai/primitives/score
 *
 * @phpstan-import-type Content from Question
 */
final class Score extends Question
{
    /** @var list<Content> */
    private array $criteria = [];

    /**
     * @param Content $instructions
     */
    public static function ask(string|array|null $instructions = null): self
    {
        return (new self())->instructions($instructions);
    }

    /**
     * Start from a rubric: descriptions in order, indexed by score from zero.
     *
     * @param list<Content> $levels
     */
    public static function rubric(array $levels): self
    {
        return (new self())->levels($levels);
    }

    /**
     * Append the next level of the rubric. The first call describes score 0.
     *
     * @param Content $description
     */
    public function level(string|array|null $description): self
    {
        $this->criteria[] = $description;

        return $this;
    }

    /**
     * Append several levels in order.
     *
     * @param list<Content> $levels
     */
    public function levels(array $levels): self
    {
        foreach ($levels as $description) {
            $this->level($description);
        }

        return $this;
    }

    /**
     * @return list<Content>
     */
    public function getLevels(): array
    {
        return $this->criteria;
    }

    public function type(): string
    {
        return 'score';
    }

    public function validate(string $name): void
    {
        if (count($this->criteria) < 2) {
            throw new TypeSafeException(sprintf(
                'Score question "%s" has %d level(s); a rubric needs at least two.',
                $name,
                count($this->criteria),
            ));
        }
    }

    protected function payload(): array
    {
        return ['criteria' => $this->criteria];
    }
}
