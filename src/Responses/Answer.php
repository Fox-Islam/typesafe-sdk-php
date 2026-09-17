<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

use Phox\TypeSafe\Exceptions\TypeSafeException;

/**
 * One question's answer. The concrete class mirrors the question that produced
 * it: {@see NoulAnswer}, {@see ChoiceAnswer}, {@see ScoreAnswer}.
 */
abstract class Answer
{
    /**
     * @param array<string, mixed> $data
     */
    final private function __construct(protected readonly array $data) {}

    /** The discriminator the API sent. */
    abstract public function type(): string;

    /**
     * The decoded answer object, including any fields this SDK does not model.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Build the answer class matching the payload's `type`, or `null` for a type
     * this SDK version does not model.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        return match ($data['type'] ?? null) {
            'noul' => new NoulAnswer($data),
            'choice' => new ChoiceAnswer($data),
            'score' => new ScoreAnswer($data),
            default => null,
        };
    }

    protected function float(string $key): float
    {
        $value = $this->data[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new TypeSafeException(sprintf('Expected a number for "%s" in a %s answer.', $key, $this->type()));
        }

        return (float) $value;
    }

    /**
     * @return array<array-key, float>
     */
    protected function floatMap(string $key): array
    {
        $values = $this->data[$key] ?? [];

        if (! is_array($values)) {
            throw new TypeSafeException(sprintf('Expected a map for "%s" in a %s answer.', $key, $this->type()));
        }

        return array_map(static fn (mixed $value): float => (float) (is_numeric($value) ? $value : 0), $values);
    }
}
