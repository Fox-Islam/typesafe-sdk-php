<?php

declare(strict_types=1);

/**
 * Triage a support ticket. Run with TYPESAFE_API_KEY set:
 *
 *     php examples/demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Phox\TypeSafe\Client;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Score;

$ticket = [
    'subject' => 'Charged twice this month',
    'body' => 'I was charged twice on the 3rd. Please refund one of them ASAP, my card is now overdrawn.',
    'plan' => 'pro',
];

try {
    $response = (new Client())->systemOne()
        ->state($ticket)
        ->ask('category', Choice::ask('What is this ticket about?')
            ->option('billing', 'Charges, invoices and refunds')
            ->option('technical', 'Something in the product is broken')
            ->option('other'))
        ->ask('blocked', Noul::ask('Is the customer blocked right now?')
            ->yes('They cannot carry on until someone acts')
            ->no('They have a workaround, or nothing is stopping them'))
        ->ask('urgency', Score::ask('How urgent is this ticket?')
            ->level('Can wait until next week')
            ->level('Should be handled today')
            ->level('Needs someone right now'))
        ->send();
} catch (TypeSafeException $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$category = $response->choice('category');
$urgency = $response->score('urgency');

printf("Model:    %s\n", $response->model());
printf("Category: %s (%.0f%% confident)\n", $category->choice(), $category->confidence() * 100);
printf("Blocked:  %s (%.2f)\n", $response->noul('blocked')->isYes() ? 'yes' : 'no', $response->noul('blocked')->noul());
printf("Urgency:  %.2f — %s\n", $urgency->score(), (string) $urgency->describe());
printf("Tokens:   %d\n", $response->usage()->totalTokens() ?? 0);
