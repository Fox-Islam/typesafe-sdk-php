<?php

declare(strict_types=1);

namespace Phox\TypeSafe\Responses;

/**
 * Token counts for a request, when the API reports them, and the charge for it
 * when the provider prices the call rather than the account does.
 */
final class Usage
{
    private function __construct(
        private readonly ?int $inputTokens,
        private readonly ?int $outputTokens,
        private readonly ?float $cost,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_numeric($data['input_tokens'] ?? null) ? (int) $data['input_tokens'] : null,
            is_numeric($data['output_tokens'] ?? null) ? (int) $data['output_tokens'] : null,
            is_numeric($data['cost'] ?? null) ? (float) $data['cost'] : null,
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

    /**
     * What the call cost, in US dollars, or `null` when the provider did not say.
     *
     * OpenRouter prices every call and reports it here; calling TypeSafe directly
     * does not, because billing is settled on the account rather than per request.
     */
    public function cost(): ?float
    {
        return $this->cost;
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
     * @return array{input_tokens: int|null, output_tokens: int|null, cost: float|null}
     */
    public function toArray(): array
    {
        return ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'cost' => $this->cost];
    }
}
