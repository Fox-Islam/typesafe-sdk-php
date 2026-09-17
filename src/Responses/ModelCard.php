<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

use DateTimeImmutable;

/**
 * Metadata for one model available to the account.
 */
final class ModelCard
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private readonly array $data) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function name(): string
    {
        return is_string($this->data['name'] ?? null) ? $this->data['name'] : '';
    }

    public function description(): string
    {
        return is_string($this->data['description'] ?? null) ? $this->data['description'] : '';
    }

    /** The release date as the API sent it. */
    public function releaseDate(): ?string
    {
        return is_string($this->data['release_date'] ?? null) ? $this->data['release_date'] : null;
    }

    /** The release date parsed, or `null` when it is missing or unparseable. */
    public function releasedOn(): ?DateTimeImmutable
    {
        $date = $this->releaseDate();

        if ($date === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The decoded model card, including any fields this SDK does not model.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
