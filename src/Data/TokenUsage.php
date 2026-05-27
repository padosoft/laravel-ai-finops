<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

/**
 * Token counts for a single AI call. `cached` and `reasoning` are subsets that
 * many providers bill differently; they are tracked separately for accurate
 * costing and are NOT re-added into `input`/`output` by this object.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cached = 0,
        public int $reasoning = 0,
    ) {}

    public function total(): int
    {
        return $this->input + $this->output;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            input: (int) ($data['input'] ?? 0),
            output: (int) ($data['output'] ?? 0),
            cached: (int) ($data['cached'] ?? 0),
            reasoning: (int) ($data['reasoning'] ?? 0),
        );
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'cached' => $this->cached,
            'reasoning' => $this->reasoning,
        ];
    }
}
