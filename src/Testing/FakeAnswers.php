<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Testing;

use Phox\TypeSafe\Support\Json;
use Phox\TypeSafe\TypeSafe;

/**
 * A System One response body, written in terms of answers rather than JSON.
 *
 * ```php
 * FakeAnswers::make()
 *     ->noul('urgent', true)
 *     ->choice('category', 'billing')
 *     ->score('severity', 2.0);
 * ```
 *
 * Only the answers a test cares about need scripting: every other question the
 * call asked is answered by {@see SimulatedAnswer}, and gaps in a scripted
 * answer — a choice's probabilities, a score's legend — are filled in from the
 * question that was asked, the way the API would fill them.
 */
final class FakeAnswers
{
    /** Probability a `true` yes/no answer comes back with; a `false` one is its complement. */
    public const float YES = 0.9;

    /** @var array<string, callable(array<string, mixed>|null): array<string, mixed>> */
    private array $answers = [];

    /** @var list<string> */
    private array $omitted = [];

    private ?string $model = null;

    /** @var array{input_tokens?: int|null, output_tokens?: int|null, cost?: float|null}|null */
    private ?array $usage = null;

    private ?string $id = null;
    private ?string $provider = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * A yes/no answer, as a probability of yes or as the bare verdict.
     */
    public function noul(string $name, float|bool $probability = true): self
    {
        $value = is_bool($probability) ? ($probability ? self::YES : round(1.0 - self::YES, 4)) : $probability;

        return $this->put($name, static fn (?array $question): array => ['type' => 'noul', 'noul' => $value]);
    }

    /**
     * A chosen label. Probabilities default to `$confidence` on the label and
     * the rest split evenly over the other options the question offered.
     *
     * @param array<string, float>|null $probabilities
     */
    public function choice(string $name, string $label, ?array $probabilities = null, ?float $confidence = null): self
    {
        $confidence ??= SimulatedAnswer::CONFIDENCE;

        return $this->put($name, static function (?array $question) use ($label, $probabilities, $confidence): array {
            if ($probabilities === null) {
                $labels = SimulatedAnswer::labels($question);
                /** @var array<string, float> $probabilities */
                $probabilities = SimulatedAnswer::spread($labels, $label, $confidence);
            }

            return [
                'type' => 'choice',
                'choice' => $label,
                'confidence' => $probabilities[$label] ?? $confidence,
                'probabilities' => $probabilities,
            ];
        });
    }

    /**
     * A score on the rubric the question sent. The legend comes back from that
     * rubric, and probabilities default to `$confidence` on the nearest level.
     *
     * @param array<int, float>|null $probabilities
     * @param array<int, mixed>|null $legend
     */
    public function score(
        string $name,
        float $score,
        ?float $confidence = null,
        ?array $probabilities = null,
        ?array $legend = null,
    ): self {
        $confidence ??= SimulatedAnswer::CONFIDENCE;
        $nearest = (int) round($score);

        return $this->put($name, static function (?array $question) use ($score, $nearest, $confidence, $probabilities, $legend): array {
            $levels = SimulatedAnswer::levels($question);

            if ($probabilities === null) {
                $keys = $levels === [] ? [$nearest] : range(0, count($levels) - 1);
                /** @var array<int, float> $probabilities */
                $probabilities = SimulatedAnswer::spread($keys, $nearest, $confidence);
            }

            return [
                'type' => 'score',
                'score' => $score,
                'confidence' => $probabilities[$nearest] ?? $confidence,
                'legend' => $legend ?? SimulatedAnswer::legend($levels),
                'probabilities' => $probabilities,
            ];
        });
    }

    /**
     * An answer payload exactly as the API would send it, for shapes the
     * helpers above do not cover.
     *
     * @param array<string, mixed> $payload
     */
    public function raw(string $name, array $payload): self
    {
        return $this->put($name, static fn (?array $question): array => $payload);
    }

    /**
     * Leave a question unanswered, as the API does when it drops one.
     */
    public function omit(string $name): self
    {
        unset($this->answers[$name]);
        $this->omitted[] = $name;

        return $this;
    }

    /** The model the response reports; defaults to the one the call asked for. */
    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Token counts, and the cost when simulating a provider that prices calls.
     */
    public function usage(?int $inputTokens, ?int $outputTokens = null, ?float $cost = null): self
    {
        $this->usage = ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens, 'cost' => $cost];

        return $this;
    }

    /** The generation id in the body, as OpenRouter sends it. */
    public function id(?string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /** Who served the call, as OpenRouter reports it. */
    public function provider(?string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /** Any other top-level field, for API features this SDK version does not model. */
    public function with(string $key, mixed $value): self
    {
        $this->extra[$key] = $value;

        return $this;
    }

    /**
     * The response body, completed against the call it answers.
     *
     * @return array<string, mixed>
     */
    public function toArray(?FakeCall $call = null): array
    {
        $questions = $call?->questions() ?? [];
        $answers = [];

        foreach ($questions as $name => $question) {
            if (in_array($name, $this->omitted, true)) {
                continue;
            }

            $answer = isset($this->answers[$name])
                ? ($this->answers[$name])($question)
                : SimulatedAnswer::forQuestion($question);

            if ($answer !== null) {
                $answers[$name] = $answer;
            }
        }

        // Answers scripted for questions this call did not ask still come back,
        // so a test can simulate the API answering something unexpected.
        foreach ($this->answers as $name => $factory) {
            if (! isset($answers[$name]) && ! in_array($name, $this->omitted, true)) {
                $answers[$name] = $factory(null);
            }
        }

        $body = [
            'model' => $this->model ?? $call?->model() ?? TypeSafe::DEFAULT_MODEL,
            'answers' => $answers,
            'usage' => $this->usage ?? self::estimateUsage($call, $answers),
        ];

        if ($this->id !== null) {
            $body['id'] = $this->id;
        }

        if ($this->provider !== null) {
            $body['provider'] = $this->provider;
        }

        return $body + $this->extra;
    }

    /**
     * Cast the maps inside each answer to objects, so a legend or a set of
     * probabilities keyed by score encodes as `{"0": …}` rather than as a list.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function toWire(array $body): array
    {
        if (! is_array($body['answers'] ?? null)) {
            return $body;
        }

        $answers = [];
        foreach ($body['answers'] as $name => $answer) {
            if (is_array($answer)) {
                foreach (['probabilities', 'legend'] as $key) {
                    if (is_array($answer[$key] ?? null)) {
                        $answer[$key] = (object) $answer[$key];
                    }
                }
            }

            $answers[$name] = $answer;
        }

        $body['answers'] = (object) $answers;

        return $body;
    }

    /**
     * @param callable(array<string, mixed>|null): array<string, mixed> $factory
     */
    private function put(string $name, callable $factory): self
    {
        $this->answers[$name] = $factory;
        $this->omitted = array_values(array_filter($this->omitted, static fn (string $omitted): bool => $omitted !== $name));

        return $this;
    }

    /**
     * Token counts in the right ballpark, derived from the request so the same
     * call always reports the same usage. Script {@see usage()} to assert on it.
     *
     * @param array<string, mixed> $answers
     * @return array{input_tokens: int, output_tokens: int, cost: null}
     */
    private static function estimateUsage(?FakeCall $call, array $answers): array
    {
        $request = $call === null ? '' : Json::encode($call->body());

        return [
            'input_tokens' => (int) ceil(strlen($request) / 4),
            'output_tokens' => 8 * count($answers),
            'cost' => null,
        ];
    }
}
