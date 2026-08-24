<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

use DateTimeImmutable;
use Padosoft\LaravelAiFinOps\Enums\CallStatus;
use Padosoft\LaravelAiFinOps\Enums\CostMethod;
use Padosoft\LaravelAiFinOps\Enums\Modality;

/**
 * The single, provider-agnostic description of one AI call. It flows through the
 * metering hook (estimate → record) and is the cross-package "glue": any Padosoft
 * package can populate the context tags (tenant, cost-center, agent step, purpose,
 * trace id) so FinOps can attribute and govern spend consistently.
 *
 * Immutable: the `with*` helpers return a new instance.
 */
final readonly class AiCallEnvelope
{
    public function __construct(
        public string $traceId,
        public string $provider,
        public string $model,
        public Modality $modality = Modality::Text,
        public CallStatus $status = CallStatus::Estimated,
        public TokenUsage $tokens = new TokenUsage,
        public CostBreakdown $cost = new CostBreakdown,
        public ?string $spanId = null,
        public ?string $parentSpanId = null,
        public string|int|null $tenantId = null,
        public string|int|null $userId = null,
        public ?string $costCenter = null,
        public ?string $agentStep = null,
        public ?string $purposeTag = null,
        public ?int $latencyMs = null,
        public ?DateTimeImmutable $occurredAt = null,
        /** @var array<string,mixed> */
        public array $metadata = [],
        // How the cost was derived + whether tokens were estimated + the provider's
        // real invoiced amount when known (distinct from the tariff-computed cost).
        public CostMethod $costMethod = CostMethod::Computed,
        public bool $tokensEstimated = false,
        public ?float $billedCost = null,
        public ?string $billedCurrency = null,
        // IAM delegation attribution (trailing for positional BC): the call ran under
        // a delegated token — the id is the `pds_dgr` claim / iam_delegation_grants id.
        public ?string $delegationGrantId = null,
    ) {}

    public function withStatus(CallStatus $status): self
    {
        return $this->copyWith(['status' => $status]);
    }

    public function withTokens(TokenUsage $tokens): self
    {
        return $this->copyWith(['tokens' => $tokens]);
    }

    public function withCost(CostBreakdown $cost): self
    {
        return $this->copyWith(['cost' => $cost]);
    }

    public function withLatency(int $latencyMs): self
    {
        return $this->copyWith(['latencyMs' => $latencyMs]);
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        return $this->copyWith(['metadata' => [...$this->metadata, ...$metadata]]);
    }

    /**
     * Flatten to a ledger row (snake_case columns, JSON-encoded sub-objects).
     *
     * @return array<string,mixed>
     */
    public function toLedgerRow(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'provider' => $this->provider,
            'model' => $this->model,
            'modality' => $this->modality->value,
            'status' => $this->status->value,
            'tenant_id' => $this->tenantId === null ? null : (string) $this->tenantId,
            'user_id' => $this->userId === null ? null : (string) $this->userId,
            'cost_center' => $this->costCenter,
            'agent_step' => $this->agentStep,
            'purpose_tag' => $this->purposeTag,
            'tokens_input' => $this->tokens->input,
            'tokens_output' => $this->tokens->output,
            'tokens_cached' => $this->tokens->cached,
            'tokens_reasoning' => $this->tokens->reasoning,
            'cost_input' => $this->cost->input,
            'cost_output' => $this->cost->output,
            'cost_cached' => $this->cost->cached,
            'cost_total' => $this->cost->total,
            'currency' => $this->cost->currency,
            'latency_ms' => $this->latencyMs,
            'cost_method' => $this->costMethod->value,
            'tokens_estimated' => $this->tokensEstimated,
            'billed_cost' => $this->billedCost,
            'billed_currency' => $this->billedCurrency,
            'delegation_grant_id' => $this->delegationGrantId,
            // Returned as a raw array; the UsageRecord model's `array` cast encodes
            // it on write (encoding here too would double-encode the JSON).
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            traceId: (string) $data['trace_id'],
            provider: (string) $data['provider'],
            model: (string) $data['model'],
            modality: Modality::from((string) ($data['modality'] ?? Modality::Text->value)),
            status: CallStatus::from((string) ($data['status'] ?? CallStatus::Estimated->value)),
            tokens: TokenUsage::fromArray([
                'input' => $data['tokens_input'] ?? 0,
                'output' => $data['tokens_output'] ?? 0,
                'cached' => $data['tokens_cached'] ?? 0,
                'reasoning' => $data['tokens_reasoning'] ?? 0,
            ]),
            cost: CostBreakdown::fromArray([
                'total' => $data['cost_total'] ?? 0.0,
                'input' => $data['cost_input'] ?? 0.0,
                'output' => $data['cost_output'] ?? 0.0,
                'cached' => $data['cost_cached'] ?? 0.0,
                'currency' => $data['currency'] ?? 'USD',
            ]),
            spanId: $data['span_id'] ?? null,
            parentSpanId: $data['parent_span_id'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            userId: $data['user_id'] ?? null,
            costCenter: $data['cost_center'] ?? null,
            agentStep: $data['agent_step'] ?? null,
            purposeTag: $data['purpose_tag'] ?? null,
            latencyMs: isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            metadata: self::decodeMetadata($data['metadata'] ?? null),
            costMethod: CostMethod::from((string) ($data['cost_method'] ?? CostMethod::Computed->value)),
            tokensEstimated: (bool) ($data['tokens_estimated'] ?? false),
            billedCost: isset($data['billed_cost']) ? (float) $data['billed_cost'] : null,
            billedCurrency: $data['billed_currency'] ?? null,
            delegationGrantId: $data['delegation_grant_id'] ?? null,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function copyWith(array $overrides): self
    {
        return new self(
            traceId: $overrides['traceId'] ?? $this->traceId,
            provider: $overrides['provider'] ?? $this->provider,
            model: $overrides['model'] ?? $this->model,
            modality: $overrides['modality'] ?? $this->modality,
            status: $overrides['status'] ?? $this->status,
            tokens: $overrides['tokens'] ?? $this->tokens,
            cost: $overrides['cost'] ?? $this->cost,
            spanId: $overrides['spanId'] ?? $this->spanId,
            parentSpanId: $overrides['parentSpanId'] ?? $this->parentSpanId,
            tenantId: $overrides['tenantId'] ?? $this->tenantId,
            userId: $overrides['userId'] ?? $this->userId,
            costCenter: $overrides['costCenter'] ?? $this->costCenter,
            agentStep: $overrides['agentStep'] ?? $this->agentStep,
            purposeTag: $overrides['purposeTag'] ?? $this->purposeTag,
            latencyMs: $overrides['latencyMs'] ?? $this->latencyMs,
            occurredAt: $overrides['occurredAt'] ?? $this->occurredAt,
            metadata: $overrides['metadata'] ?? $this->metadata,
            costMethod: $overrides['costMethod'] ?? $this->costMethod,
            tokensEstimated: $overrides['tokensEstimated'] ?? $this->tokensEstimated,
            billedCost: $overrides['billedCost'] ?? $this->billedCost,
            billedCurrency: $overrides['billedCurrency'] ?? $this->billedCurrency,
            delegationGrantId: $overrides['delegationGrantId'] ?? $this->delegationGrantId,
        );
    }

    /**
     * @param  string|array<string,mixed>|null  $raw
     * @return array<string,mixed>
     */
    private static function decodeMetadata(string|array|null $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
