<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Support;

/**
 * Ambient agentic context for metering attribution — the cross-package "glue".
 * laravel-flow (or any caller) sets a trace id + step/cost-center/purpose around a
 * unit of work; the metering hook stamps every AI call in that scope, so a single
 * agent run's cost can be attributed per step under one trace id.
 */
class TraceContext
{
    private ?string $traceId = null;

    private ?string $agentStep = null;

    private ?string $costCenter = null;

    private ?string $purposeTag = null;

    private string|int|null $tenantId = null;

    public function set(
        ?string $traceId = null,
        ?string $agentStep = null,
        ?string $costCenter = null,
        ?string $purposeTag = null,
        string|int|null $tenantId = null,
    ): void {
        $this->traceId = $traceId ?? $this->traceId;
        $this->agentStep = $agentStep ?? $this->agentStep;
        $this->costCenter = $costCenter ?? $this->costCenter;
        $this->purposeTag = $purposeTag ?? $this->purposeTag;
        $this->tenantId = $tenantId ?? $this->tenantId;
    }

    public function clear(): void
    {
        $this->traceId = $this->agentStep = $this->costCenter = $this->purposeTag = null;
        $this->tenantId = null;
    }

    /**
     * Run a callback within a trace scope, restoring the previous context after.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function within(array $context, callable $callback): mixed
    {
        $previous = [$this->traceId, $this->agentStep, $this->costCenter, $this->purposeTag, $this->tenantId];

        $this->set(
            traceId: $context['trace_id'] ?? null,
            agentStep: $context['agent_step'] ?? null,
            costCenter: $context['cost_center'] ?? null,
            purposeTag: $context['purpose_tag'] ?? null,
            tenantId: $context['tenant_id'] ?? null,
        );

        try {
            return $callback();
        } finally {
            [$this->traceId, $this->agentStep, $this->costCenter, $this->purposeTag, $this->tenantId] = $previous;
        }
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function agentStep(): ?string
    {
        return $this->agentStep;
    }

    public function costCenter(): ?string
    {
        return $this->costCenter;
    }

    public function purposeTag(): ?string
    {
        return $this->purposeTag;
    }

    public function tenantId(): string|int|null
    {
        return $this->tenantId;
    }
}
