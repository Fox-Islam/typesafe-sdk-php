<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Assets\Image;
use Phox\TypeSafe\Exceptions\BadRequestException;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Box;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use Phox\TypeSafe\Testing\SimulatedAnswer;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class BoxTest extends ClientTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\n" . 'pixels';

    #[Test]
    public function a_box_question_sends_its_instructions_and_no_criteria(): void
    {
        self::assertSame(
            '{"type":"box","instructions":"The dog"}',
            (string) json_encode(Box::locate('The dog')),
        );
    }

    #[Test]
    public function a_box_answer_reads_its_edges_and_confidence(): void
    {
        $this->transport->queue(['model' => 'jev-preview', 'answers' => [
            'dog' => ['type' => 'box', 'box' => ['top' => 0.1, 'bottom' => 0.6, 'left' => 0.25, 'right' => 0.75], 'confidence' => 0.88],
        ]]);
        $photo = Image::fromBytes(self::PNG);

        $answer = $this->client()->systemOne()->state($photo)->box('dog', 'The dog')->send()->box('dog');

        self::assertSame(['top' => 0.1, 'bottom' => 0.6, 'left' => 0.25, 'right' => 0.75], $answer->box());
        self::assertSame(0.88, $answer->confidence());
        self::assertSame([200, 40, 400, 200], $answer->inPixels(800, 400));
    }

    #[Test]
    public function a_box_answer_without_a_confidence_reports_null(): void
    {
        $this->transport->queue(['model' => 'jev-preview', 'answers' => [
            'dog' => ['type' => 'box', 'box' => ['top' => 0, 'bottom' => 1, 'left' => 0, 'right' => 1], 'confidence' => null],
        ]]);
        $photo = Image::fromBytes(self::PNG);

        $response = $this->client()->systemOne()->state($photo)->box('dog')->send();

        self::assertNull($response->box('dog')->confidence());
        self::assertSame(['dog'], array_keys($response->boxes()));
    }

    #[Test]
    public function asking_for_a_box_from_another_kind_of_answer_names_both(): void
    {
        $this->transport->queue(['model' => 'jev-latest', 'answers' => ['dog' => ['type' => 'noul', 'noul' => 0.5]]]);

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Answer "dog" is a noul answer, not box.');

        $this->client()->systemOne()->state('text')->noul('dog')->send()->box('dog');
    }

    #[Test]
    public function a_box_question_goes_to_openrouter_with_its_image_inline(): void
    {
        $this->transport->queue(['model' => 'typesafe/jev-1.14', 'answers' => ['dog' => ['type' => 'box', 'box' => ['top' => 0.1, 'bottom' => 0.6, 'left' => 0.25, 'right' => 0.75]]]]);
        $photo = Image::fromBytes(self::PNG);

        $answer = $this->client()->openRouter()->systemOne()->state($photo)->box('dog', 'The dog')->send()->box('dog');

        self::assertSame(0.25, $answer->left());
        self::assertSame(
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . base64_encode(self::PNG)]],
            $this->transport->lastBody()['state'],
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function oldBuildRejections(): array
    {
        $typesafe = ['detail' => ['error_type' => 'api_usage_error', 'message' => 'Invalid request.']];

        return [
            'TypeSafe' => [$typesafe],
            'OpenRouter' => [['error' => ['message' => 'HTTP 400: ' . json_encode($typesafe), 'code' => 400]]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('oldBuildRejections')]
    public function a_bare_400_to_a_box_question_names_the_build_it_needs(array $body): void
    {
        $this->transport->queue($body, 400, ['x-typesafe-request-id' => 'req-1']);

        try {
            $this->client()->systemOne()->model('jev-latest')->state('text')->box('dog')->send();
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $exception) {
            self::assertStringContainsString('Invalid request.', $exception->getMessage());
            self::assertStringContainsString('Box questions need Jev 1.14 or newer; "jev-latest" may resolve to an older build.', $exception->getMessage());
            self::assertSame($body, $exception->getBody());
            self::assertSame('req-1', $exception->getRequestId());
        }
    }

    #[Test]
    public function a_400_naming_its_cause_is_not_blamed_on_the_build(): void
    {
        $this->transport->queue(['detail' => ['error_type' => 'api_usage_error', 'message' => 'Invalid image dimensions.']], 400);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('400 Invalid image dimensions.');

        try {
            $this->client()->systemOne()->state('text')->box('dog')->send();
        } catch (BadRequestException $exception) {
            self::assertStringNotContainsString('Jev 1.14', $exception->getMessage());
            throw $exception;
        }
    }

    #[Test]
    public function a_bare_400_without_a_box_question_has_no_hint(): void
    {
        $this->transport->queue(['detail' => ['error_type' => 'api_usage_error', 'message' => 'Invalid request.']], 400);

        try {
            $this->client()->systemOne()->state('text')->noul('dog')->send();
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $exception) {
            self::assertSame('400 Invalid request.', $exception->getMessage());
        }
    }

    #[Test]
    public function the_fake_answers_an_unscripted_box_with_the_whole_image(): void
    {
        $fake = new FakeTypeSafe();
        $photo = Image::fromBytes(self::PNG);

        $answer = $fake->client()->systemOne()->state($photo)->box('dog', 'The dog')->send()->box('dog');

        self::assertSame(SimulatedAnswer::WHOLE_IMAGE, $answer->box());
        self::assertSame([$photo->reference() => self::PNG], $fake->lastCall()->images());
        self::assertSame('box', $fake->lastCall()->questionType('dog'));
    }

    #[Test]
    public function the_fake_answers_a_scripted_box(): void
    {
        $fake = (new FakeTypeSafe())->reply(FakeAnswers::make()->box('dog', top: 0.1, left: 0.2, bottom: 0.3, right: 0.4, confidence: null));
        $photo = Image::fromBytes(self::PNG);

        $answer = $fake->client()->systemOne()->state($photo)->box('dog')->send()->box('dog');

        self::assertSame(['top' => 0.1, 'bottom' => 0.3, 'left' => 0.2, 'right' => 0.4], $answer->box());
        self::assertNull($answer->confidence());
    }
}
