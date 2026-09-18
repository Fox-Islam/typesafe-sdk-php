<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Requests;

use Phox\TypeSafe\Config;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Http\Requester;
use Phox\TypeSafe\Http\RequestOptions;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Question;
use Phox\TypeSafe\Questions\Score;
use Phox\TypeSafe\Responses\SystemOneResponse;
use Phox\TypeSafe\Retry\RetryPolicy;

/**
 * Builds and sends a System One call: some state, and the questions to answer
 * about it.
 *
 * ```php
 * $client->systemOne()
 *     ->state($ticket)
 *     ->ask('category', Choice::between(['billing', 'technical', 'other']))
 *     ->ask('urgent', Noul::ask('Is the customer blocked?'))
 *     ->send();
 * ```
 *
 * @see https://docs.typesafe.ai/concepts/system-one
 *
 * @phpstan-import-type Content from Question
 */
final class SystemOne
{
    /** @var Content */
    private string|array|null $state = null;

    /** @var array<string, Question> */
    private array $questions = [];

    private ?string $model = null;
    private ?float $timeout = null;

    /** @var array<string, string> */
    private array $headers = [];

    private ?RetryPolicy $retry = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    public function __construct(
        private readonly Requester $requester,
        private readonly Config $config,
    ) {}

    /**
     * What the questions are about: text, or a JSON-ready array.
     *
     * @param Content $state
     */
    public function state(string|array|null $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Ask one question, keyed by the name its answer will come back under.
     */
    public function ask(string $name, Question $question): self
    {
        $this->questions[$name] = $question;

        return $this;
    }

    /**
     * Ask several questions at once, keyed by answer name.
     *
     * @param array<string, Question> $questions
     */
    public function questions(array $questions): self
    {
        foreach ($questions as $name => $question) {
            $this->ask((string) $name, $question);
        }

        return $this;
    }

    /**
     * Shorthand for a yes/no question with no criteria.
     *
     * @param Content $instructions
     */
    public function noul(string $name, string|array|null $instructions = null): self
    {
        return $this->ask($name, Noul::ask($instructions));
    }

    /**
     * Shorthand for a choice question over a list of labels or a map of label to description.
     *
     * @param list<string>|array<string, Content> $options
     * @param Content $instructions
     */
    public function choice(string $name, array $options, string|array|null $instructions = null): self
    {
        return $this->ask($name, Choice::between($options)->instructions($instructions));
    }

    /**
     * Shorthand for a score question over an ordered rubric.
     *
     * @param list<Content> $levels
     * @param Content $instructions
     */
    public function score(string $name, array $levels, string|array|null $instructions = null): self
    {
        return $this->ask($name, Score::rubric($levels)->instructions($instructions));
    }

    /**
     * Override the model for this call only.
     */
    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Override the per-attempt timeout, in seconds, for this call only.
     */
    public function timeout(float $seconds): self
    {
        $this->timeout = Config::assertTimeout($seconds);

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Narrow the retry policy for this call, either with a policy or with a
     * callback that receives the client's policy and returns a modified copy.
     *
     * @param RetryPolicy|(callable(RetryPolicy): RetryPolicy) $retry
     */
    public function retry(RetryPolicy|callable $retry): self
    {
        $this->retry = $retry instanceof RetryPolicy ? $retry : $retry($this->config->getRetry());

        return $this;
    }

    /**
     * Send an additional top-level field with the request body, for API
     * features this SDK version does not model yet.
     */
    public function with(string $key, mixed $value): self
    {
        $this->extra[$key] = $value;

        return $this;
    }

    /**
     * The request body as it will be sent, with the model resolved.
     *
     * Choice criteria appear as objects rather than arrays, so that a map of
     * labels never encodes as a JSON list.
     *
     * @return array<string, mixed>
     *
     * @throws TypeSafeException No questions were asked, or one of them is incomplete.
     */
    public function toArray(): array
    {
        $this->validate();

        return [
            'state' => $this->state,
            'model' => $this->model ?? $this->config->getDefaultModel(),
            'questions' => array_map(static fn (Question $question): array => $question->jsonSerialize(), $this->questions),
        ] + $this->extra;
    }

    /**
     * Send the request and return the answers.
     *
     * @throws TypeSafeException No questions were asked, or one of them is incomplete.
     * @throws \Phox\TypeSafe\Exceptions\ApiException The API rejected the request.
     * @throws \Phox\TypeSafe\Exceptions\ConnectionException The request never completed.
     */
    public function send(): SystemOneResponse
    {
        $response = $this->requester->send(
            'POST',
            $this->config->getProvider()->systemOnePath(),
            $this->toArray(),
            new RequestOptions($this->timeout, $this->headers, $this->retry),
        );

        return SystemOneResponse::fromArray($response->data(), $response->requestId());
    }

    /**
     * @throws TypeSafeException
     */
    private function validate(): void
    {
        if ($this->questions === []) {
            throw new TypeSafeException('At least one question is required.');
        }

        foreach ($this->questions as $name => $question) {
            $question->validate($name);
        }
    }
}
