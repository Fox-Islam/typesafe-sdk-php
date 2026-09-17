<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * @phpstan-type Record array{level: string, message: string, context: array<mixed>}
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        $records = $level === null
            ? $this->records
            : array_filter($this->records, static fn (array $record): bool => $record['level'] === $level);

        return array_values(array_map(static fn (array $record): string => $record['message'], $records));
    }
}
