<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Unit;

use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Tests\Support\ClientTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ModelsTest extends ClientTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'models' => [
                ['name' => 'jev-latest', 'description' => 'The current model', 'release_date' => '2026-02-01'],
                ['name' => 'jev-2025-11', 'description' => 'Previous generation', 'release_date' => '2025-11-14'],
            ],
        ];
    }

    #[Test]
    public function it_lists_the_models_available_to_the_account(): void
    {
        $this->transport->queue($this->payload(), headers: ['x-typesafe-request-id' => 'req_9']);

        $models = $this->client()->models()->list();

        self::assertSame('GET', $this->transport->lastRequest()->getMethod());
        self::assertSame('https://api.typesafe.ai/v1/models', (string) $this->transport->lastRequest()->getUri());
        self::assertFalse($this->transport->lastRequest()->hasHeader('Content-Type'));

        self::assertCount(2, $models);
        self::assertSame(['jev-latest', 'jev-2025-11'], $models->names());
        self::assertSame('The current model', $models->first()?->description());
        self::assertSame('req_9', $models->requestId());
    }

    #[Test]
    public function a_model_can_be_looked_up_by_name(): void
    {
        $this->transport->queue($this->payload());

        $model = $this->client()->models()->find('jev-2025-11');

        self::assertNotNull($model);
        self::assertSame('2025-11-14', $model->releaseDate());
        self::assertSame('2025-11-14', $model->releasedOn()?->format('Y-m-d'));
    }

    #[Test]
    public function an_unknown_model_comes_back_as_null(): void
    {
        $this->transport->queue($this->payload());

        self::assertNull($this->client()->models()->find('jev-imaginary'));
    }

    #[Test]
    public function the_models_can_be_iterated(): void
    {
        $this->transport->queue($this->payload());

        $names = [];
        foreach ($this->client()->models()->list() as $model) {
            $names[] = $model->name();
        }

        self::assertSame(['jev-latest', 'jev-2025-11'], $names);
    }

    #[Test]
    public function a_response_that_is_not_a_json_object_is_reported(): void
    {
        $this->transport->queue('not json at all');

        $this->expectException(TypeSafeException::class);
        $this->expectExceptionMessage('Expected a JSON object from the API, got string.');

        $this->client()->models()->list();
    }

    #[Test]
    public function a_response_without_models_is_empty_rather_than_fatal(): void
    {
        $this->transport->queue(['unexpected' => true]);

        self::assertTrue($this->client()->models()->list()->isEmpty());
    }
}
