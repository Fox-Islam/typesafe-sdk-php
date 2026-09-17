<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

/**
 * Token counts for a request, when the API reports them.
 */
final class Usage
{
    private function __construct(
        private readonly ?int $inputTokens,
        private readonly ?int $outputTokens,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_numeric($data['input_tokens'] ?? null) ? (int) $data['input_tokens'] : null,
            is_numeric($data['output_tokens'] ?? null) ? (int) $data['output_tokens'] : null,
        );
    }

    public function inputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): ?int
    {
        return $this->outputTokens;
    }

    /** Input and output tokens combined, or `null` when neither was reported. */
    public function totalTokens(): ?int
    {
        if ($this->inputTokens === null && $this->outputTokens === null) {
            return null;
        }

        return ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
    }

    /**
     * @return array{input_tokens: int|null, output_tokens: int|null}
     */
    public function toArray(): array
    {
        return ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens];
    }
}
