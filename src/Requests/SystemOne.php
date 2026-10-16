<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Requests;

use Phox\TypeSafe\Assets\Image;
use Phox\TypeSafe\Config;
use Phox\TypeSafe\Exceptions\BadRequestException;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Http\Requester;
use Phox\TypeSafe\Http\RequestOptions;
use Phox\TypeSafe\Questions\Box;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Question;
use Phox\TypeSafe\Questions\Score;
use Phox\TypeSafe\Responses\SystemOneResponse;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\Support\Json;

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
    private string|array|Image|null $state = null;

    /** @var array<string, Question> */
    private array $questions = [];

    private ?string $model = null;
    private ?float $timeout = null;

    /** @var array<string, string> */
    private array $headers = [];

    private ?RetryPolicy $retry = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    /** @var array<string, Image> */
    private array $assets = [];

    public function __construct(
        private readonly Requester $requester,
        private readonly Config $config,
    ) {}

    /**
     * What the questions are about: text, an image, or a JSON-ready array.
     *
     * @param Content $state
     */
    public function state(string|array|Image|null $state): self
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
    public function noul(string $name, string|array|Image|null $instructions = null): self
    {
        return $this->ask($name, Noul::ask($instructions));
    }

    /**
     * Shorthand for a choice question over a list of labels or a map of label to description.
     *
     * @param list<string>|array<string, Content> $options
     * @param Content $instructions
     */
    public function choice(string $name, array $options, string|array|Image|null $instructions = null): self
    {
        return $this->ask($name, Choice::between($options)->instructions($instructions));
    }

    /**
     * Shorthand for a score question over an ordered rubric.
     *
     * @param list<Content> $levels
     * @param Content $instructions
     */
    public function score(string $name, array $levels, string|array|Image|null $instructions = null): self
    {
        return $this->ask($name, Score::rubric($levels)->instructions($instructions));
    }

    /**
     * Shorthand for a question locating something in the request's image.
     *
     * @param Content $instructions What to locate.
     */
    public function box(string $name, string|array|Image|null $instructions = null): self
    {
        return $this->ask($name, Box::locate($instructions));
    }

    /**
     * Send an image under a reference of your choosing, for text that names it
     * instead of interpolating it, such as a prompt kept in config:
     *
     * ```php
     * ->state('The customer returned <delivered>.')
     * ->asset('<delivered>', Image::fromPath($path))
     * ```
     *
     * Pick a reference that cannot occur in ordinary text; every occurrence is
     * replaced by the image.
     */
    public function asset(string $reference, Image $image): self
    {
        $this->assets[$reference] = $image;

        return $this;
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
     * labels never encodes as a JSON list. Calling TypeSafe, images appear as
     * themselves and encode as their references, and `assets` maps each
     * reference to the multipart part its file is uploaded in. On OpenRouter
     * each image is an `image_url` part where it goes, with no `assets`.
     *
     * @return array<string, mixed>
     *
     * @throws TypeSafeException No questions were asked, one of them is incomplete, or an image reference cannot be resolved.
     */
    public function toArray(): array
    {
        return $this->build()['body'];
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
        $request = $this->build();

        try {
            $response = $this->requester->send(
                'POST',
                $this->config->getProvider()->systemOnePath(),
                $request['body'],
                new RequestOptions($this->timeout, $this->headers, $this->retry),
                $request['files'],
            );
        } catch (BadRequestException $exception) {
            throw $this->explainBox($exception, $request['body']['model']);
        }

        return SystemOneResponse::fromArray($response->data(), $response->requestId());
    }

    /**
     * Jev 1.13 answers a box question with a bare `Invalid request.`, which
     * OpenRouter wraps in its own message. Other image errors name their
     * cause, such as `Invalid image dimensions.`, and pass through unchanged.
     */
    private function explainBox(BadRequestException $exception, mixed $model): BadRequestException
    {
        $boxes = array_filter($this->questions, static fn (Question $question): bool => $question instanceof Box);

        if ($boxes === [] || ! str_contains($exception->getMessage(), 'Invalid request.')) {
            return $exception;
        }

        return new BadRequestException(
            $exception->getStatus(),
            $exception->getBody(),
            $exception->getHeaders(),
            sprintf(
                '%s Box questions need Jev 1.14 or newer; "%s" may resolve to an older build.',
                $exception->getMessage(),
                is_string($model) ? $model : '',
            ),
        );
    }

    /**
     * The body, and the image files to upload with it by part name.
     *
     * @return array{body: array<string, mixed>, files: array<string, Image>}
     *
     * @throws TypeSafeException
     */
    private function build(): array
    {
        $this->validate();

        $body = [
            'state' => $this->state,
            'model' => $this->model ?? $this->config->getDefaultModel(),
            'questions' => array_map(static fn (Question $question): array => $question->jsonSerialize(), $this->questions),
        ] + $this->extra;

        $images = $this->images(Json::encode($body));

        if ($images === []) {
            return ['body' => $body, 'files' => []];
        }

        if ($this->config->getProvider()->inlinesImages()) {
            return ['body' => self::inline($body, $images), 'files' => []];
        }

        // The API rejects a part shared by two references with `400 Invalid
        // image request.`, so an image sent under two references is uploaded twice.
        $assets = $files = [];
        foreach ($images as $reference => $image) {
            $part = 'asset-' . count($files);
            $files[$part] = $image;
            $assets[$reference] = ['type' => 'image', 'part' => $part];
        }

        return ['body' => $body + ['assets' => $assets], 'files' => $files];
    }

    /**
     * The body with every image written as an `image_url` part where it goes.
     * A string naming only an image becomes its part; a string with text
     * around one becomes a list of the text and the parts, in order.
     *
     * @param array<string, Image> $images By the reference each is written as.
     */
    private static function inline(mixed $value, array $images): mixed
    {
        return match (true) {
            $value instanceof Image => self::part($value),
            is_string($value) => self::split($value, $images),
            is_array($value) => array_map(static fn (mixed $item): mixed => self::inline($item, $images), $value),
            $value instanceof \stdClass => (object) self::inline(get_object_vars($value), $images),
            $value instanceof \JsonSerializable => self::inline($value->jsonSerialize(), $images),
            default => $value,
        };
    }

    /**
     * @param array<string, Image> $images
     *
     * @return string|array<string, mixed>|list<string|array<string, mixed>>
     */
    private static function split(string $text, array $images): string|array
    {
        $references = array_keys($images);
        // Longest first, so a name that starts another name never splits it.
        usort($references, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '/(' . implode('|', array_map(static fn (string $reference): string => preg_quote($reference, '/'), $references)) . ')/';

        $pieces = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        if (! array_intersect($pieces, $references)) {
            return $text;
        }

        $content = array_map(static fn (string $piece): string|array => isset($images[$piece]) ? self::part($images[$piece]) : $piece, $pieces);

        return count($content) === 1 ? $content[0] : $content;
    }

    /**
     * @return array{type: 'image_url', image_url: array{url: string}}
     */
    private static function part(Image $image): array
    {
        return ['type' => 'image_url', 'image_url' => ['url' => $image->dataUri()]];
    }

    /**
     * Every image the encoded body refers to, by reference: those named with
     * {@see asset()}, then those placed or interpolated, in order of appearance.
     *
     * @return array<string, Image>
     *
     * @throws TypeSafeException A named asset is never referred to, or a reference has no image.
     */
    private function images(string $json): array
    {
        $unused = array_filter(
            array_keys($this->assets),
            // Matched as the name appears in JSON, with quotes and backslashes escaped.
            static fn (string $reference): bool => ! str_contains($json, substr(Json::encode($reference), 1, -1)),
        );

        if ($unused !== []) {
            throw new TypeSafeException(sprintf(
                'Assets not referred to anywhere in the request: %s. Write each reference into the state, instructions or criteria.',
                implode(', ', $unused),
            ));
        }

        $images = $this->assets;
        $missing = [];

        preg_match_all(Image::REFERENCE_PATTERN, $json, $matches);
        foreach ($matches[0] as $reference) {
            if (isset($images[$reference])) {
                continue;
            }

            $image = Image::find($reference);
            if ($image === null) {
                $missing[$reference] = $reference;
            } else {
                $images[$reference] = $image;
            }
        }

        if ($missing !== []) {
            throw new TypeSafeException(sprintf(
                'Image references with no image behind them: %s. Keep the Image in a variable until the call is sent, or name it with asset().',
                implode(', ', $missing),
            ));
        }

        return $images;
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
