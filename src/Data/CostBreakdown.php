<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

/**
 * Monetary breakdown of a call, in the envelope's currency. All amounts are in
 * major units (e.g. dollars) as floats; the ledger persists them with high
 * precision. `total` is authoritative and may include surcharges/discounts not
 * captured by the input/output split.
 */
final readonly class CostBreakdown
{
    public function __construct(
        public float $total = 0.0,
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cached = 0.0,
        public string $currency = 'USD',
    ) {}

    public static function zero(string $currency = 'USD'): self
    {
        return new self(currency: $currency);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            total: (float) ($data['total'] ?? 0.0),
            input: (float) ($data['input'] ?? 0.0),
            output: (float) ($data['output'] ?? 0.0),
            cached: (float) ($data['cached'] ?? 0.0),
            currency: (string) ($data['currency'] ?? 'USD'),
        );
    }

    /** @return array<string,float|string> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'input' => $this->input,
            'output' => $this->output,
            'cached' => $this->cached,
            'currency' => $this->currency,
        ];
    }
}
