A PHP SDK for the [TypeSafe AI](https://typesafe.ai) API.

Ask a model named questions about a piece of text or a JSON structure, and get
back answers shaped by the questions you asked — a probability for a yes/no
question, a label for a choice, a level on a rubric for a score.

Jev is reachable two ways: directly from TypeSafe, or through OpenRouter. One
call switches between them and nothing else about your code changes.

## Install

```sh
composer require phox/typesafe-sdk-php
```

Requires PHP 8.3 or newer.

## Quickstart

Set `TYPESAFE_API_KEY` in your environment (or `OPENROUTER_API_KEY` — see
[Providers](#providers)), then:

```php
use Phox\TypeSafe\Client;

$client = new Client();

$response = $client->systemOne()
    ->state('I was charged twice. Please fix this ASAP.')
    ->choice('category', ['billing', 'technical', 'other'], 'What is this ticket about?')
    ->send();

echo $response->choice('category')->choice();      // 'billing'
echo $response->choice('category')->confidence();  // 0.91
```

## Providers

Jev is served both by TypeSafe directly and by OpenRouter's decisions endpoint.
The request and answer bodies are the same on both, so the provider only decides
the host, the path and which key is read:

```php
$client = Client::make()->openRouter();   // reads OPENROUTER_API_KEY
```

or set `TYPESAFE_PROVIDER=openrouter` and construct the client normally.

| | TypeSafe | OpenRouter |
| --- | --- | --- |
| Host | `https://api.typesafe.ai` | `https://openrouter.ai` |
| Path | `/v1/systemone` | `/api/alpha/decisions` |
| Key | `TYPESAFE_API_KEY` | `OPENROUTER_API_KEY` |
| Request id | `x-typesafe-request-id` | `x-generation-id`, also in the body |
| Per-call cost | not reported | `$response->usage()->cost()` |
| `models()->list()` | yes | no catalogue — name the model directly |

`baseUrl` and `apiKey` follow the provider when you switch, unless you set
either of them yourself, in which case yours is kept:

```php
Client::make()->openRouter()->getBaseUrl();                     // openrouter.ai
Client::make(baseUrl: 'https://proxy.example.com')->openRouter()
    ->getBaseUrl();                                             // proxy.example.com
```

### Model names

`jev-latest` — the SDK default — works on both providers and resolves to the
same build. OpenRouter also accepts its own namespaced names:

| Name | Meaning |
| --- | --- |
| `jev-latest` | the current build, on either provider |
| `~typesafe/jev-latest` | the same, in OpenRouter's naming |
| `typesafe/jev-1.13` | a pinned build |

The leading `~` marks a floating alias and pairs only with `-latest`;
`~typesafe/jev-1.13` is rejected.

### What comes back

The answers are identical either way. OpenRouter adds two things:

```php
$response = Client::make()->openRouter()->systemOne()
    ->state($ticket)->noul('urgent', 'Is the customer blocked?')->send();

$response->provider();         // 'TypeSafe' — who actually served it
$response->usage()->cost();    // charge in USD, or null calling TypeSafe directly
$response->requestId();        // the generation id
```

`cost()` returns `null` when the provider did not price the call, and `0.0` when
it priced it at nothing — those are different answers.

## Laravel

The package auto-registers a `Client` singleton, so it can be injected straight
away and nothing needs wiring:

```php
public function __construct(private readonly Client $typeSafe) {}
```

Set `TYPESAFE_API_KEY` (or `OPENROUTER_API_KEY`) and that is the whole setup.
To change anything else, publish the config:

```sh
php artisan vendor:publish --tag=typesafe-config
```

```php
// config/typesafe.php
'provider' => env('TYPESAFE_PROVIDER', 'typesafe'),
'keys' => [
    'typesafe' => env('TYPESAFE_API_KEY'),
    'openrouter' => env('OPENROUTER_API_KEY'),
],
'model' => env('TYPESAFE_DEFAULT_MODEL', 'jev-latest'),
'timeout' => (float) env('TYPESAFE_TIMEOUT', 10.0),
'log' => ['enabled' => true, 'channel' => null, 'level' => env('TYPESAFE_LOG_LEVEL', 'warn')],
```

A config value that is null or empty falls through to the environment variable
for that setting, so publishing the file does not force you to fill it in.

**Publish the config if you run `php artisan config:cache`.** With a cached
config Laravel never loads the `.env`, so a key left to an environment lookup
resolves to nothing — and because a missing key is only reported when a request
is sent, that shows up as calls failing in production rather than as a boot
error. Reading the key from config is what avoids it.

The client logs through Laravel's logger; `log.channel` names a channel and
`log.level` accepts `error`, `warn`, `info`, `debug` or `off`. Credential
headers are redacted, bodies are not, so `debug` writes your state and answers
to the log.

## Questions

Every question is built fluently and keyed by the name its answer comes back
under. Instructions and criteria may be a string or a JSON-ready array.

### Noul — yes or no

```php
use Phox\TypeSafe\Questions\Noul;

Noul::ask('Is the customer blocked right now?')
    ->yes('They cannot use the product at all')
    ->no('They have a workaround');
```

```php
$answer = $response->noul('blocked');
$answer->noul();    // 0.82 — probability of yes
$answer->isYes();   // true, at the default 0.5 threshold
$answer->isYes(0.9);
```

### Choice — one of several labels

```php
use Phox\TypeSafe\Questions\Choice;

Choice::ask('What is this ticket about?')
    ->option('billing', 'Charges, invoices and refunds')
    ->option('technical', 'Something is broken')
    ->option('other');

// Or, when the labels speak for themselves:
Choice::between(['billing', 'technical', 'other']);
```

```php
$answer = $response->choice('category');
$answer->choice();                  // 'billing'
$answer->is('billing');             // true
$answer->confidence();              // 0.91
$answer->probabilities();           // ['billing' => 0.91, 'technical' => 0.06, ...]
$answer->probabilityOf('technical');
```

### Score — a level on an ordered rubric

Levels are scored from zero, in the order you add them. A rubric needs at least
two.

```php
use Phox\TypeSafe\Questions\Score;

Score::ask('How urgent is this ticket?')
    ->level('Can wait until next week')
    ->level('Should be handled today')
    ->level('The customer is blocked right now');

// Or:
Score::rubric(['Can wait until next week', 'Should be handled today', 'The customer is blocked right now']);
```

```php
$answer = $response->score('urgency');
$answer->score();          // 1.4 — an expectation, so it falls between levels
$answer->nearestLevel();   // 1
$answer->describe();       // 'Should be handled today'
$answer->probabilities();  // [0 => 0.1, 1 => 0.4, 2 => 0.5]
```

## Asking several questions at once

```php
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Score;

$response = $client->systemOne()
    ->state([
        'subject' => 'Charged twice this month',
        'body' => $ticket->body,
        'plan' => 'pro',
    ])
    ->ask('category', Choice::between(['billing', 'technical', 'other']))
    ->ask('blocked', Noul::ask('Is the customer blocked right now?'))
    ->ask('urgency', Score::rubric(['Can wait', 'Today', 'Right now']))
    ->send();

$response->model();                  // the model that answered
$response->usage()->totalTokens();
$response->requestId();              // for support tickets about a request
```

`choice()`, `noul()` and `score()` check that an answer came back in the shape
its question asked for, so a mismatch is reported here rather than surfacing as
a missing method later. `answers()` returns all of them; `nouls()`, `choices()`
and `scores()` return each kind.

The shorthands on the request builder cover the common case without importing a
question class:

```php
$client->systemOne()
    ->state($ticket)
    ->noul('blocked', 'Is the customer blocked right now?')
    ->choice('category', ['billing', 'technical', 'other'])
    ->score('urgency', ['Can wait', 'Today', 'Right now'])
    ->send();
```

## Models

```php
$models = $client->models()->list();

$models->names();              // ['jev-latest', ...]
$models->find('jev-latest')?->description();

foreach ($models as $model) {
    echo $model->name(), ' ', $model->releaseDate(), PHP_EOL;
}
```

Only TypeSafe publishes a catalogue. On OpenRouter this throws a
`TypeSafeException` telling you to name a model directly, rather than returning
an empty list that would read as "no models available".

## Configuring the client

Values given in code win over environment variables, which win over the SDK
defaults.

| Setting | Environment variable | Default |
| --- | --- | --- |
| `apiKey` | `TYPESAFE_API_KEY` or `OPENROUTER_API_KEY` | — (required) |
| `provider` | `TYPESAFE_PROVIDER` | `typesafe` |
| `baseUrl` | `TYPESAFE_BASE_URL` | the provider's host |
| `defaultModel` | `TYPESAFE_DEFAULT_MODEL` | `jev-latest` |
| `logLevel` | `TYPESAFE_LOG_LEVEL` | `warn` |
| `timeout` | — | 10 seconds per attempt |

Which key is read follows the provider, so both can sit in the environment at
once and only the relevant one is used.

```php
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Retry\RetryPolicy;

$client = Client::make($apiKey)
    ->openRouter()
    ->defaultModel('jev-latest')
    ->timeout(30)
    ->header('X-Tenant', 'acme')
    ->retry(fn (RetryPolicy $policy) => $policy->maxRetries(4))
    ->logger($psrLogger)
    ->logLevel(LogLevel::Info);
```

The client's setters mutate it and return it, so it can be built in one chain
and reused. Per-call overrides live on the request builder and never touch the
client:

```php
$client->systemOne()
    ->state($ticket)
    ->noul('blocked')
    ->model('jev-2026-01')
    ->timeout(5)
    ->header('X-Request-Source', 'support-inbox')
    ->retry(RetryPolicy::none())
    ->send();
```

## Retries

By default the SDK retries twice, on HTTP 408, 429 and 5xx, and on connection
failures and timeouts. Backoff starts at 500ms, doubles up to 5s, and has up to
25% of itself randomly subtracted. A `Retry-After` or `retry-after-ms` header is
honoured when it asks for a minute or less.

```php
$client->retry(fn (RetryPolicy $policy) => $policy
    ->maxRetries(4)
    ->initialBackoff(0.25)
    ->maxBackoff(8)
    ->jitter(0.5)
    ->retryStatuses(429, 502, 503, 504)
    ->maxRetryAfter(30)
    ->retryTimeouts(false));
```

The timeout applies to each attempt, not to the call as a whole, so a request
that keeps timing out can take `timeout × (maxRetries + 1)` plus backoff.

`RetryPolicy` setters return a modified copy, so narrowing a policy for one call
leaves the client's own policy alone.

## Errors

Everything the SDK throws extends `TypeSafeException`.

| Exception | Raised when |
| --- | --- |
| `TypeSafeException` | Configuration or question validation failed, before anything was sent |
| `ApiException` | The API returned a non-2xx response |
| `BadRequestException` | HTTP 400 |
| `AuthenticationException` | HTTP 401 |
| `PermissionDeniedException` | HTTP 403 |
| `NotFoundException` | HTTP 404 |
| `UnprocessableEntityException` | HTTP 422 |
| `RateLimitException` | HTTP 429 |
| `InternalServerException` | HTTP 5xx |
| `ConnectionException` | The request never produced a complete response |
| `TimeoutException` | An attempt ran out of time; a kind of `ConnectionException` |

```php
use Phox\TypeSafe\Exceptions\ApiException;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\RateLimitException;

try {
    $response = $client->systemOne()->state($ticket)->noul('blocked')->send();
} catch (RateLimitException $exception) {
    $waitFor = $exception->getRetryAfter();
} catch (ApiException $exception) {
    $exception->getStatus();
    $exception->getRequestId();
    $exception->getBody();
} catch (ConnectionException $exception) {
    // Retries are already exhausted by this point.
}
```

## Logging

Nothing is logged until you give the client a PSR-3 logger. `info` writes a line
per attempt; `debug` adds request headers and bodies. Credential headers are
redacted — bodies are not, so `debug` will log whatever state you send.

```php
$client->logger($monolog)->logLevel(LogLevel::Debug);
```

## Using another HTTP client

Requests go through Guzzle by default. Any PSR-18 client works too, though
PSR-18 has no per-request timeout, so configure one on the client you pass in:

```php
$client->httpClient($psr18Client);
```

For anything else — another HTTP stack, or a transport of your own — implement
`Phox\TypeSafe\Contracts\Transport`. Faking the API in tests needs none of
this: see [Testing](#testing).

```php
use Phox\TypeSafe\Contracts\Transport;

final class RecordingTransport implements Transport
{
    public function send(RequestInterface $request, float $timeout): ResponseInterface
    {
        // Retries, logging and error mapping happen above this.
    }
}

$client->transport(new RecordingTransport());
```

## Testing

Tests should not call Jev: the answers move, the network fails, and a suite that
depends on either is flaky. `Phox\TypeSafe\Testing\FakeTypeSafe` ships with the
package and answers in its place.

```php
use Phox\TypeSafe\Testing\FakeTypeSafe;

$fake = new FakeTypeSafe();

$router = new TicketRouter($fake->client());
$router->route('I was charged twice.');

self::assertSame('I was charged twice.', $fake->lastCall()->state());
```

Nothing has to be scripted. The fake reads the questions off the request and
answers every one of them in the shape that question asked for, identically on
every run: a yes at `0.75`, the first label, level `0`, with the rest of the
probability split evenly over the other outcomes. A score answer comes back with
the rubric it was asked about as its legend, as the API sends it.

`$fake->client()` is a client built for this — no API key, no network, and
retries off, so a failure you queue surfaces as itself. To fake the client an
application already configured, including the singleton Laravel registered,
point that one at the fake instead:

```php
$fake->bind($this->app->make(Client::class));
```

### Scripting answers

`FakeAnswers` writes a response in answers rather than in JSON. Script what the
test asserts on; anything else it asked stays simulated.

```php
use Phox\TypeSafe\Testing\FakeAnswers;

$fake->reply(
    FakeAnswers::make()
        ->choice('category', 'billing')     // probabilities fill in from the options asked
        ->noul('urgent', true)              // 0.9, or pass a probability of yes
        ->score('severity', 2.0),
);
```

| Method | What it scripts |
| --- | --- |
| `noul($name, 0.82\|true\|false)` | a yes/no answer |
| `choice($name, $label, $probabilities?, $confidence?)` | the selected label |
| `score($name, $score, $confidence?, $probabilities?, $legend?)` | a score on the rubric |
| `raw($name, $payload)` | an answer payload verbatim |
| `omit($name)` | leaves a question unanswered, as a dropped answer would |
| `model()`, `usage()`, `provider()`, `id()`, `with()` | the fields around the answers |

Each `reply()` covers one call, in order; `alwaysReply()` covers every System
One call the queue does not. Both also take a callback, which receives the call
and returns the answers, for a fake that responds to what was asked:

```php
$fake->alwaysReply(fn (FakeCall $call) => FakeAnswers::make()
    ->noul('urgent', str_contains((string) $call->state(), 'ASAP')));
```

### Failures

```php
$fake->fail(429);                                  // a RateLimitException
$fake->fail(500, times: 3);                        // enough for a client that retries
$fake->throw(new TimeoutException(10.0));          // nothing came back at all
```

### Models

`models()->list()` comes back with one card for `jev-latest` unless a test says
otherwise:

```php
$fake->models(['jev-1.13' => 'A pinned build', 'jev-latest']);
```

### What was asked

`$fake->calls()` holds a `FakeCall` per request, decoded so assertions read in
terms of questions rather than JSON.

| | |
| --- | --- |
| `state()`, `model()` | what the call was about, and the model it resolved to |
| `questions()`, `question($name)`, `questionType($name)`, `asked($name)` | the questions as sent |
| `method()`, `url()`, `path()`, `headers()`, `header($name)` | the request itself |
| `retryCount()`, `timeout()` | which attempt this was, and what it was allowed |
| `body()`, `request()` | the raw payload and the PSR-7 request |

`$fake->callCount()`, `$fake->lastCall()`, `$fake->asked($name)` and
`$fake->systemOneCalls()` cover the common assertions; `$fake->reset()` clears
calls and scripts between them.

For a test that needs a particular status code or body rather than answers,
`Phox\TypeSafe\Testing\FakeTransport` is the layer underneath, and takes both:

```php
$client->transport((new FakeTransport())->queue('<html>502</html>', 502));
```

## Development

```sh
composer install
composer test
composer phpstan
```
