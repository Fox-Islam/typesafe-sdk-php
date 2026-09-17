<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Score;
use Phox\TypeSafe\Responses\ChoiceAnswer;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SystemOneTest extends ClientTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function answersPayload(): array
    {
        return [
            'model' => 'jev-latest',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 8],
            'answers' => [
                'category' => [
                    'type' => 'choice',
                    'choice' => 'billing',
                    'confidence' => 0.91,
                    'probabilities' => ['billing' => 0.91, 'technical' => 0.06, 'other' => 0.03],
                ],
                'urgent' => ['type' => 'noul', 'noul' => 0.82],
                'severity' => [
                    'type' => 'score',
                    'score' => 1.4,
                    'confidence' => 0.7,
                    'legend' => ['0' => 'Can wait', '1' => 'Today', '2' => 'Right now'],
                    'probabilities' => ['0' => 0.1, '1' => 0.4, '2' => 0.5],
                ],
            ],
        ];
    }

    #[Test]
    public function it_posts_state_questions_and_the_resolved_model(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()
            ->defaultModel('jev-2026-01')
            ->systemOne()
            ->state('I was charged twice.')
            ->ask('urgent', Noul::ask('Is the customer blocked?'))
            ->ask('category', Choice::between(['billing' => 'Charges', 'technical' => null]))
            ->send();

        $request = $this->transport->lastRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.typesafe.ai/v1/systemone', (string) $request->getUri());
        self::assertSame([
            'state' => 'I was charged twice.',
            'model' => 'jev-2026-01',
            'questions' => [
                'urgent' => ['type' => 'noul', 'instructions' => 'Is the customer blocked?'],
                'category' => ['type' => 'choice', 'instructions' => null, 'criteria' => ['billing' => 'Charges', 'technical' => null]],
            ],
        ], $this->transport->lastBody());
    }

    #[Test]
    public function a_per_call_model_beats_the_client_default(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()
            ->defaultModel('jev-2026-01')
            ->systemOne()
            ->state('hello')
            ->noul('urgent')
            ->model('jev-experimental')
            ->send();

        self::assertSame('jev-experimental', $this->transport->lastBody()['model']);
    }

    #[Test]
    public function state_may_be_structured(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()->systemOne()
            ->state(['subject' => 'Double charge', 'tier' => 'pro'])
            ->noul('urgent')
            ->send();

        self::assertSame(['subject' => 'Double charge', 'tier' => 'pro'], $this->transport->lastBody()['state']);
    }

    #[Test]
    public function the_shorthand_helpers_build_the_same_questions(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()->systemOne()
            ->state('hello')
            ->noul('urgent', 'Is the customer blocked?')
            ->choice('category', ['billing', 'other'], 'What is this about?')
            ->score('severity', ['Can wait', 'Today'], 'How bad is it?')
            ->send();

        self::assertSame([
            'urgent' => ['type' => 'noul', 'instructions' => 'Is the customer blocked?'],
            'category' => ['type' => 'choice', 'instructions' => 'What is this about?', 'criteria' => ['billing' => null, 'other' => null]],
            'severity' => ['type' => 'score', 'instructions' => 'How bad is it?', 'criteria' => ['Can wait', 'Today']],
        ], $this->transport->lastBody()['questions']);
    }

    #[Test]
    public function extra_body_fields_are_forwarded(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()->systemOne()
            ->state('hello')
            ->noul('urgent')
            ->with('trace_id', 'abc-123')
            ->send();

        self::assertSame('abc-123', $this->transport->lastBody()['trace_id']);
    }

    #[Test]
    public function it_sends_the_api_key_and_sdk_headers(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()->systemOne()->state('hello')->noul('urgent')->send();

        $request = $this->transport->lastRequest();

        self::assertSame('Bearer test-api-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringStartsWith('typesafe-sdk-php/', $request->getHeaderLine('User-Agent'));
        self::assertStringStartsWith('typesafe-sdk-php/', $request->getHeaderLine('X-TypeSafe-SDK'));
        self::assertStringStartsWith('php/', $request->getHeaderLine('X-TypeSafe-Runtime'));
        self::assertFalse($request->hasHeader('X-TypeSafe-Retry-Count'));
    }

    #[Test]
    public function caller_headers_cannot_displace_the_authorization_header(): void
    {
        $this->transport->queue($this->answersPayload());

        $this->client()
            ->header('X-Tenant', 'acme')
            ->systemOne()
            ->state('hello')
            ->noul('urgent')
            ->header('authorization', 'Bearer hijacked')
            ->header('X-Request-Source', 'support-inbox')
            ->send();

        $request = $this->transport->lastRequest();

        self::assertSame('Bearer test-api-key', $request->getHeaderLine('Authorization'));
        self::assertSame('acme', $request->getHeaderLine('X-Tenant'));
        self::assertSame('support-inbox', $request->getHeaderLine('X-Request-Source'));
    }

    #[Test]
    public function it_returns_answers_typed_by_the_question_that_asked_them(): void
    {
        $this->transport->queue($this->answersPayload() + ['x' => 1]);

        $response = $this->client()->systemOne()
            ->state('I was charged twice.')
            ->ask('category', Choice::between(['billing', 'technical', 'other']))
            ->ask('urgent', Noul::ask('Is the customer blocked?'))
            ->ask('severity', Score::rubric(['Can wait', 'Today', 'Right now']))
            ->send();

        self::assertSame('jev-latest', $response->model());
        self::assertSame(128, $response->usage()->totalTokens());

        self::assertSame('billing', $response->choice('category')->choice());
        self::assertTrue($response->choice('category')->is('billing'));
        self::assertSame(0.06, $response->choice('category')->probabilityOf('technical'));

        self::assertSame(0.82, $response->noul('urgent')->noul());
        self::assertTrue($response->noul('urgent')->isYes());

        self::assertSame(1.4, $response->score('severity')->score());
        self::assertSame(1, $response->score('severity')->nearestLevel());
        self::assertSame('Today', $response->score('severity')->describe());
        self::assertSame(0.5, $response->score('severity')->probabilityOf(2));

        self::assertCount(1, $response->nouls());
        self::assertCount(1, $response->choices());
        self::assertCount(1, $response->scores());
    }

    #[Test]
    public function reading_an_answer_as_the_wrong_type_says_so(): void
    {
        $this->transport->queue($this->answersPayload());

        $response = $this->client()->systemOne()->state('hello')->noul('urgent')->send();

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Answer "category" is a choice answer, not noul.');

        $response->noul('category');
    }

    #[Test]
    public function reading_a_missing_answer_lists_the_ones_that_came_back(): void
    {
        $this->transport->queue($this->answersPayload());

        $response = $this->client()->systemOne()->state('hello')->noul('urgent')->send();

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('No answer named "missing" in the response; got: category, urgent, severity.');

        $response->answer('missing');
    }

    #[Test]
    public function answer_types_this_version_does_not_model_are_skipped(): void
    {
        $this->transport->queue([
            'model' => 'jev-latest',
            'usage' => [],
            'answers' => [
                'urgent' => ['type' => 'noul', 'noul' => 0.3],
                'mystery' => ['type' => 'something-new', 'value' => 42],
            ],
        ]);

        $response = $this->client()->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame(['urgent'], array_keys($response->answers()));
        self::assertFalse($response->has('mystery'));
        // The raw payload is still there for anyone who needs it.
        self::assertSame(42, $response->toArray()['answers']['mystery']['value']);
        self::assertNull($response->usage()->totalTokens());
    }

    #[Test]
    public function it_exposes_the_request_id(): void
    {
        $this->transport->queue($this->answersPayload(), headers: ['x-typesafe-request-id' => 'req_123']);

        $response = $this->client()->systemOne()->state('hello')->noul('urgent')->send();

        self::assertSame('req_123', $response->requestId());
    }

    #[Test]
    public function a_request_without_questions_never_reaches_the_api(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('At least one question is required.');

        try {
            $this->client()->systemOne()->state('hello')->send();
        } finally {
            self::assertSame(0, $this->transport->callCount());
        }
    }

    #[Test]
    public function an_incomplete_question_never_reaches_the_api(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Score question "severity" has 0 level(s)');

        try {
            $this->client()->systemOne()->state('hello')->ask('severity', Score::ask('How bad?'))->send();
        } finally {
            self::assertSame(0, $this->transport->callCount());
        }
    }

    #[Test]
    public function answers_keep_fields_the_sdk_does_not_model(): void
    {
        $this->transport->queue([
            'model' => 'jev-latest',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
            'answers' => [
                'category' => [
                    'type' => 'choice',
                    'choice' => 'billing',
                    'confidence' => 0.5,
                    'probabilities' => ['billing' => 0.5],
                    'rationale' => 'mentions a double charge',
                ],
            ],
        ]);

        $answer = $this->client()->systemOne()->state('hello')->choice('category', ['billing'])->send()->answer('category');

        self::assertInstanceOf(ChoiceAnswer::class, $answer);
        self::assertSame('mentions a double charge', $answer->toArray()['rationale']);
    }
}
