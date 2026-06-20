<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

/**
 * Monetary breakdown of a call, in the envelope's currency. Amounts are in major
 * units (e.g. dollars). They are computed as floats (the pricing cascade is
 * float arithmetic) and the ledger persists them with high precision.
 *
 * Money is financial data, so as of v1.3 each amount is ALSO exposed as a
 * **fixed-precision formatted decimal string** at {@see self::SCALE} decimals —
 * a stable, deterministic serialization for APIs and storage (it is `number_format`'d
 * from the float, not true arbitrary-precision decimal arithmetic). Exposed via the
 * `*Decimal()` accessors and the additive `*_decimal` keys in {@see toArray()}. The
 * original `float` fields and `total`/`input`/`output`/`cached` keys are KEPT
 * (back-compatible) for existing consumers that do float arithmetic or charting;
 * consumers that want a stable string form should read the `*_decimal` keys.
 */
final readonly class CostBreakdown
{
    /** Decimal scale for the fixed-precision string representation of money. */
    public const SCALE = 8;

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

    /** Fixed-precision decimal string of `total` (8 dp), e.g. "0.00024100". */
    public function totalDecimal(): string
    {
        return self::decimal($this->total);
    }

    /** Fixed-precision decimal string of `input` (8 dp). */
    public function inputDecimal(): string
    {
        return self::decimal($this->input);
    }

    /** Fixed-precision decimal string of `output` (8 dp). */
    public function outputDecimal(): string
    {
        return self::decimal($this->output);
    }

    /** Fixed-precision decimal string of `cached` (8 dp). */
    public function cachedDecimal(): string
    {
        return self::decimal($this->cached);
    }

    /**
     * Format an amount as a fixed-precision decimal string at {@see self::SCALE}
     * decimals with a '.' separator and no thousands separator.
     */
    public static function decimal(float $amount): string
    {
        return number_format($amount, self::SCALE, '.', '');
    }

    /** @return array<string,float|string> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'input' => $this->input,
            'output' => $this->output,
            'cached' => $this->cached,
            // v1.3 — fixed-precision formatted decimal strings (additive, stable serialization).
            'total_decimal' => $this->totalDecimal(),
            'input_decimal' => $this->inputDecimal(),
            'output_decimal' => $this->outputDecimal(),
            'cached_decimal' => $this->cachedDecimal(),
            'currency' => $this->currency,
        ];
    }
}
