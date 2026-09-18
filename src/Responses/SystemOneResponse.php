<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

use Phox\TypeSafe\Exceptions\TypeSafeException;

/**
 * Answers keyed by question name, with the model that produced them and the
 * tokens they cost.
 *
 * The typed accessors — {@see noul()}, {@see choice()}, {@see score()} — assert
 * that an answer came back in the shape the question asked for, so a mismatch
 * surfaces here rather than as a missing method further down.
 */
final class SystemOneResponse
{
    /**
     * @param array<string, Answer> $answers
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly string $model,
        private readonly array $answers,
        private readonly Usage $usage,
        private readonly array $data,
        private readonly ?string $requestId,
        private readonly ?string $provider,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $requestId = null): self
    {
        $answers = [];
        $raw = is_array($data['answers'] ?? null) ? $data['answers'] : [];

        foreach ($raw as $name => $answer) {
            if (! is_array($answer)) {
                continue;
            }

            // Answer types a future API adds are left out rather than fatal; the
            // payload is still readable through toArray().
            $answers[(string) $name] = Answer::fromArray($answer);
        }

        // OpenRouter puts the generation id in the body as well as the header;
        // fall back to it so requestId() is answerable whichever provider ran the call.
        $bodyId = is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : null;

        return new self(
            is_string($data['model'] ?? null) ? $data['model'] : '',
            array_filter($answers, static fn (?Answer $answer): bool => $answer !== null),
            Usage::fromArray(is_array($data['usage'] ?? null) ? $data['usage'] : []),
            $data,
            $requestId ?? $bodyId,
            is_string($data['provider'] ?? null) && $data['provider'] !== '' ? $data['provider'] : null,
        );
    }

    /** The model that answered the request. */
    public function model(): string
    {
        return $this->model;
    }

    public function usage(): Usage
    {
        return $this->usage;
    }

    /**
     * The id to quote in a bug report: `x-typesafe-request-id` from TypeSafe,
     * or the generation id from OpenRouter.
     */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * Who actually served the call, when the response names them — `TypeSafe`
     * for a Jev call routed through OpenRouter. `null` when calling TypeSafe
     * directly, where there is only one answer.
     */
    public function provider(): ?string
    {
        return $this->provider;
    }

    /**
     * Every answer, keyed by question name.
     *
     * @return array<string, Answer>
     */
    public function answers(): array
    {
        return $this->answers;
    }

    /**
     * @throws TypeSafeException No answer came back under that name.
     */
    public function answer(string $name): Answer
    {
        return $this->answers[$name]
            ?? throw new TypeSafeException(sprintf(
                'No answer named "%s" in the response; got: %s.',
                $name,
                $this->answers === [] ? 'none' : implode(', ', array_keys($this->answers)),
            ));
    }

    public function has(string $name): bool
    {
        return isset($this->answers[$name]);
    }

    /**
     * @throws TypeSafeException The answer is missing or is not a yes/no answer.
     */
    public function noul(string $name): NoulAnswer
    {
        return $this->expect($name, NoulAnswer::class);
    }

    /**
     * @throws TypeSafeException The answer is missing or is not a choice answer.
     */
    public function choice(string $name): ChoiceAnswer
    {
        return $this->expect($name, ChoiceAnswer::class);
    }

    /**
     * @throws TypeSafeException The answer is missing or is not a score answer.
     */
    public function score(string $name): ScoreAnswer
    {
        return $this->expect($name, ScoreAnswer::class);
    }

    /**
     * @return array<string, NoulAnswer>
     */
    public function nouls(): array
    {
        /** @var array<string, NoulAnswer> */
        return array_filter($this->answers, static fn (Answer $answer): bool => $answer instanceof NoulAnswer);
    }

    /**
     * @return array<string, ChoiceAnswer>
     */
    public function choices(): array
    {
        /** @var array<string, ChoiceAnswer> */
        return array_filter($this->answers, static fn (Answer $answer): bool => $answer instanceof ChoiceAnswer);
    }

    /**
     * @return array<string, ScoreAnswer>
     */
    public function scores(): array
    {
        /** @var array<string, ScoreAnswer> */
        return array_filter($this->answers, static fn (Answer $answer): bool => $answer instanceof ScoreAnswer);
    }

    /**
     * The decoded response body, including anything this SDK does not model.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @template T of Answer
     * @param class-string<T> $expected
     * @return T
     */
    private function expect(string $name, string $expected): Answer
    {
        $answer = $this->answer($name);

        if (! $answer instanceof $expected) {
            throw new TypeSafeException(sprintf(
                'Answer "%s" is a %s answer, not %s.',
                $name,
                $answer->type(),
                match ($expected) {
                    NoulAnswer::class => 'noul',
                    ChoiceAnswer::class => 'choice',
                    default => 'score',
                },
            ));
        }

        return $answer;
    }
}
