<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Client;
use Phox\TypeSafe\Exceptions\RateLimitException;
use Phox\TypeSafe\Exceptions\TimeoutException;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Responses\SystemOneResponse;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use Phox\TypeSafe\Testing\SimulatedAnswer;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FakeTypeSafeTest extends ClientTestCase
{
    private FakeTypeSafe $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeTypeSafe();
    }

    private function ask(?Client $client = null): SystemOneResponse
    {
        return ($client ?? $this->fake->client())
            ->systemOne()
            ->state('I was charged twice. Please fix this ASAP.')
            ->choice('category', ['billing', 'technical', 'other'], 'What is this about?')
            ->noul('urgent', 'Is the customer blocked?')
            ->score('severity', ['Can wait', 'Today', 'Right now'])
            ->send();
    }

    #[Test]
    public function it_answers_every_question_without_being_scripted(): void
    {
        $answers = $this->ask();

        self::assertSame('billing', $answers->choice('category')->choice());
        self::assertSame(SimulatedAnswer::CONFIDENCE, $answers->choice('category')->confidence());
        self::assertSame(['billing' => 0.75, 'technical' => 0.125, 'other' => 0.125], $answers->choice('category')->probabilities());
        self::assertTrue($answers->noul('urgent')->isYes());
        self::assertSame(0, $answers->score('severity')->nearestLevel());
        self::assertSame('Can wait', $answers->score('severity')->describe());
    }

    #[Test]
    public function it_answers_the_same_way_every_run(): void
    {
        $first = $this->ask()->toArray();
        $second = $this->ask(FakeTypeSafe::make()->client())->toArray();

        self::assertSame($first, $second);
    }

    #[Test]
    public function it_serves_scripted_answers_and_simulates_the_rest(): void
    {
        $this->fake->reply(
            FakeAnswers::make()
                ->choice('category', 'technical')
                ->noul('urgent', false),
        );

        $answers = $this->ask();

        self::assertSame('technical', $answers->choice('category')->choice());
        self::assertSame(0.75, $answers->choice('category')->probabilityOf('technical'));
        self::assertSame(0.125, $answers->choice('category')->probabilityOf('billing'));
        self::assertTrue($answers->noul('urgent')->isNo());
        self::assertTrue($answers->has('severity'));
    }

    #[Test]
    public function a_scripted_score_keeps_the_rubric_it_was_asked_about(): void
    {
        $this->fake->reply(FakeAnswers::make()->score('severity', 2.0));

        $severity = $this->ask()->score('severity');

        self::assertSame(2.0, $severity->score());
        self::assertSame('Right now', $severity->describe());
        self::assertSame(0.75, $severity->confidence());
        self::assertSame([0 => 0.125, 1 => 0.125, 2 => 0.75], $severity->probabilities());
    }

    #[Test]
    public function scripted_probabilities_and_legends_are_used_as_given(): void
    {
        $this->fake->reply(
            FakeAnswers::make()
                ->choice('category', 'other', ['billing' => 0.1, 'other' => 0.9])
                ->score('severity', 1.0, confidence: 0.5, legend: [0 => 'Low', 1 => 'High']),
        );

        $answers = $this->ask();

        self::assertSame(['billing' => 0.1, 'other' => 0.9], $answers->choice('category')->probabilities());
        self::assertSame(0.9, $answers->choice('category')->confidence());
        self::assertSame('High', $answers->score('severity')->describe());
    }

    #[Test]
    public function an_omitted_question_comes_back_unanswered(): void
    {
        $this->fake->reply(FakeAnswers::make()->omit('category'));

        $answers = $this->ask();

        self::assertFalse($answers->has('category'));
        self::assertTrue($answers->has('urgent'));

        $this->expectException(TypeSafeException::class);
        $answers->choice('category');
    }

    #[Test]
    public function it_reports_the_model_the_call_asked_for_and_a_request_id(): void
    {
        $answers = $this->fake->client()
            ->defaultModel('jev-2026-01')
            ->systemOne()
            ->state('anything')
            ->noul('urgent')
            ->send();

        self::assertSame('jev-2026-01', $answers->model());
        self::assertSame('fake-request-1', $answers->requestId());
        self::assertSame(8, $answers->usage()->outputTokens());
        self::assertNotNull($answers->usage()->inputTokens());
    }

    #[Test]
    public function usage_and_provider_can_be_scripted(): void
    {
        $this->fake->reply(FakeAnswers::make()->usage(120, 8, 0.0004)->provider('TypeSafe')->id('gen-123'));

        $answers = $this->fake->client()->systemOne()->state('x')->noul('urgent')->send();

        self::assertSame(128, $answers->usage()->totalTokens());
        self::assertSame(0.0004, $answers->usage()->cost());
        self::assertSame('TypeSafe', $answers->provider());
    }

    #[Test]
    public function it_records_what_was_asked(): void
    {
        $this->ask();

        $call = $this->fake->lastCall();

        self::assertSame(1, $this->fake->callCount());
        self::assertSame('POST', $call->method());
        self::assertSame('https://api.typesafe.ai/v1/systemone', $call->url());
        self::assertTrue($call->isSystemOne());
        self::assertSame('I was charged twice. Please fix this ASAP.', $call->state());
        self::assertSame(['category', 'urgent', 'severity'], array_keys($call->questions()));
        self::assertSame('choice', $call->questionType('category'));
        self::assertSame('What is this about?', ($call->question('category') ?? [])['instructions'] ?? null);
        self::assertTrue($this->fake->asked('severity'));
        self::assertFalse($this->fake->asked('sentiment'));
        self::assertSame(0, $call->retryCount());
        self::assertSame(10.0, $call->timeout());
    }

    #[Test]
    public function queued_replies_are_served_in_order_then_simulated(): void
    {
        $this->fake
            ->reply(FakeAnswers::make()->noul('urgent', 0.11))
            ->reply(FakeAnswers::make()->noul('urgent', 0.22));

        $client = $this->fake->client();
        $ask = fn (): float => $client->systemOne()->state('x')->noul('urgent')->send()->noul('urgent')->noul();

        self::assertSame(0.11, $ask());
        self::assertSame(0.22, $ask());
        self::assertSame(SimulatedAnswer::CONFIDENCE, $ask());
        self::assertSame(3, $this->fake->callCount());
    }

    #[Test]
    public function always_reply_covers_calls_the_queue_does_not(): void
    {
        $this->fake
            ->reply(FakeAnswers::make()->noul('urgent', 0.11))
            ->alwaysReply(fn () => FakeAnswers::make()->noul('urgent', 0.99));

        $client = $this->fake->client();
        $ask = fn (): float => $client->systemOne()->state('x')->ask('urgent', Noul::ask())->send()->noul('urgent')->noul();

        self::assertSame(0.11, $ask());
        self::assertSame(0.99, $ask());
        self::assertSame(0.99, $ask());
    }

    #[Test]
    public function it_can_fail_a_call_with_an_api_error(): void
    {
        $this->fake->fail(429);

        try {
            $this->ask();
            self::fail('The call should have raised a rate limit error.');
        } catch (RateLimitException $exception) {
            self::assertSame(429, $exception->getStatus());
            self::assertStringContainsString('Simulated 429 response', $exception->getMessage());
            self::assertSame('fake-request-1', $exception->getRequestId());
        }
    }

    #[Test]
    public function it_can_fail_a_call_with_an_exception_and_recover_on_the_next_one(): void
    {
        $this->fake->throw(new TimeoutException(2.5));

        $client = $this->fake->client();

        try {
            $client->systemOne()->state('x')->noul('urgent')->send();
            self::fail('The call should have timed out.');
        } catch (TimeoutException $exception) {
            self::assertSame(2.5, $exception->getTimeout());
        }

        self::assertTrue($client->systemOne()->state('x')->noul('urgent')->send()->noul('urgent')->isYes());
    }

    #[Test]
    public function repeated_failures_cover_a_client_that_retries(): void
    {
        $this->fake->fail(500, times: 2);

        $answers = $this->fake->client()
            ->retry(fn ($policy) => $policy->maxRetries(2)->initialBackoff(0.0)->jitter(0.0))
            ->systemOne()
            ->state('x')
            ->noul('urgent')
            ->send();

        self::assertSame(3, $this->fake->callCount());
        self::assertSame(2, $this->fake->lastCall()->retryCount());
        self::assertTrue($answers->noul('urgent')->isYes());
    }

    #[Test]
    public function it_lists_models(): void
    {
        $default = $this->fake->client()->models()->list();

        self::assertSame(['jev-latest'], $default->names());
        self::assertSame('2026-01-01', $default->first()?->releaseDate());

        $scripted = FakeTypeSafe::make()->models(['jev-1.13' => 'A pinned build', 'jev-latest'])->client()->models()->list();

        self::assertSame(['jev-1.13', 'jev-latest'], $scripted->names());
        self::assertSame('A pinned build', $scripted->find('jev-1.13')?->description());
    }

    #[Test]
    public function it_binds_an_existing_client(): void
    {
        $client = Client::make('real-looking-key')->defaultModel('jev-latest');

        $this->fake->bind($client);

        self::assertTrue($client->systemOne()->state('x')->noul('urgent')->send()->noul('urgent')->isYes());
        self::assertSame(1, $this->fake->callCount());
    }

    #[Test]
    public function scores_are_sent_as_objects_rather_than_lists(): void
    {
        $this->ask();

        $body = json_encode(FakeAnswers::toWire(FakeAnswers::make()->toArray($this->fake->lastCall())));

        self::assertStringContainsString('"legend":{"0":"Can wait","1":"Today","2":"Right now"}', (string) $body);
        self::assertStringContainsString('"probabilities":{"0":0.75,"1":0.125,"2":0.125}', (string) $body);
    }

    #[Test]
    public function reset_forgets_calls_and_scripts(): void
    {
        $this->fake->reply(FakeAnswers::make()->noul('urgent', 0.11));
        $this->ask();
        $this->fake->reset();

        self::assertSame(0, $this->fake->callCount());
        self::assertSame(SimulatedAnswer::CONFIDENCE, $this->ask()->noul('urgent')->noul());
    }
}
