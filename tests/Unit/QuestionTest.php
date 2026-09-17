<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Score;
use Phox\TypeSafe\Support\Json;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuestionTest extends TestCase
{
    #[Test]
    public function a_noul_question_sends_no_criteria_until_an_outcome_is_described(): void
    {
        $question = Noul::ask('Is this about billing?');

        self::assertSame(['type' => 'noul', 'instructions' => 'Is this about billing?'], $question->toArray());
    }

    #[Test]
    public function a_noul_question_sends_both_outcomes_once_either_is_described(): void
    {
        $question = Noul::ask('Is this about billing?')->yes('Charges or invoices');

        self::assertSame([
            'type' => 'noul',
            'instructions' => 'Is this about billing?',
            'criteria' => ['true' => 'Charges or invoices', 'false' => null],
        ], $question->toArray());
    }

    #[Test]
    public function noul_criteria_can_be_given_as_a_map(): void
    {
        $question = Noul::ask()->criteria(['true' => 'Yes it is', 'false' => 'No it is not']);

        self::assertSame([
            'type' => 'noul',
            'instructions' => null,
            'criteria' => ['true' => 'Yes it is', 'false' => 'No it is not'],
        ], $question->toArray());
    }

    #[Test]
    public function choice_options_accept_a_list_of_labels(): void
    {
        $question = Choice::between(['billing', 'technical', 'other']);

        self::assertSame(['billing' => null, 'technical' => null, 'other' => null], $question->getOptions());
    }

    #[Test]
    public function choice_options_accept_labels_mapped_to_descriptions(): void
    {
        $question = Choice::ask('What is this about?')
            ->option('billing', 'Charges, invoices and refunds')
            ->options(['technical' => 'Something is broken', 'other' => null]);

        self::assertSame(
            '{"type":"choice","instructions":"What is this about?","criteria":{"billing":"Charges, invoices and refunds","technical":"Something is broken","other":null}}',
            Json::encode($question),
        );
    }

    #[Test]
    public function a_single_choice_label_still_encodes_as_an_object(): void
    {
        self::assertStringContainsString('"criteria":{"billing":null}', Json::encode(Choice::between(['billing'])));
    }

    #[Test]
    public function a_choice_question_rejects_a_list_containing_anything_but_labels(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Choice options given as a list must be label strings.');

        Choice::between([['nested']]);
    }

    #[Test]
    public function a_choice_question_needs_at_least_one_label(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Choice question "category" has no options');

        Choice::ask('What is this about?')->validate('category');
    }

    #[Test]
    public function score_levels_are_indexed_from_zero_in_the_order_they_are_added(): void
    {
        $question = Score::ask('How urgent is this?')
            ->level('Can wait')
            ->levels(['Today', 'Right now']);

        self::assertSame([
            'type' => 'score',
            'instructions' => 'How urgent is this?',
            'criteria' => ['Can wait', 'Today', 'Right now'],
        ], $question->toArray());
    }

    #[Test]
    public function a_score_question_needs_at_least_two_levels(): void
    {
        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Score question "urgency" has 1 level(s); a rubric needs at least two.');

        Score::rubric(['Can wait'])->validate('urgency');
    }

    #[Test]
    public function instructions_may_be_structured_rather_than_text(): void
    {
        $question = Noul::ask(['ask' => 'Is this about billing?', 'context' => ['tier' => 'pro']]);

        self::assertSame(['ask' => 'Is this about billing?', 'context' => ['tier' => 'pro']], $question->getInstructions());
    }
}
