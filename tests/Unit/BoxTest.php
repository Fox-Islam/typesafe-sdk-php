<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Assets\Image;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Box;
use Phox\TypeSafe\Testing\FakeAnswers;
use Phox\TypeSafe\Testing\FakeTypeSafe;
use Phox\TypeSafe\Testing\SimulatedAnswer;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
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
    public function a_box_question_is_refused_on_openrouter_before_sending(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('The openrouter provider does not take box questions');

        $this->client()->openRouter()->systemOne()->state('text')->box('dog')->send();
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
