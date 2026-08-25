<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Data;

use DateTimeImmutable;
use DateTimeInterface;
use Padosoft\LaravelAiFinOps\Enums\RunEventKind;
use Padosoft\LaravelAiFinOps\Enums\RunEventStatus;

/**
 * One observed moment of an agent run: a generation step, or a tool the model
 * asked for. Provider-agnostic, like {@see AiCallEnvelope}, and carrying the two
 * things the response object cannot tell you afterwards — how long it took, and
 * what happened when it did not work.
 *
 * The parent pair is what turns a flat list into a tree: when an agent is used
 * as a tool of another agent, its run records the invocation and the tool call
 * it was delegated from, so the admin can show who called whom.
 */
final readonly class RunEvent
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $invocationId,
        public RunEventKind $kind,
        public RunEventStatus $status,
        public ?string $parentInvocationId = null,
        public ?string $parentToolInvocationId = null,
        public ?int $stepNumber = null,
        public ?bool $isFinalStep = null,
        public ?string $toolInvocationId = null,
        public ?string $toolName = null,
        public ?string $agent = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $finishReason = null,
        public TokenUsage $tokens = new TokenUsage,
        public float $costTotal = 0.0,
        public ?string $currency = null,
        public ?int $durationMs = null,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
        public string|int|null $tenantId = null,
        public ?string $costCenter = null,
        public ?string $delegationGrantId = null,
        public array $metadata = [],
        public ?DateTimeImmutable $occurredAt = null,
    ) {}

    /**
     * Flatten to a row of the run-events table.
     *
     * @return array<string,mixed>
     */
    public function toRow(): array
    {
        return [
            'invocation_id' => $this->invocationId,
            'parent_invocation_id' => $this->parentInvocationId,
            'parent_tool_invocation_id' => $this->parentToolInvocationId,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'step_number' => $this->stepNumber,
            'is_final_step' => $this->isFinalStep,
            'tool_invocation_id' => $this->toolInvocationId,
            'tool_name' => $this->toolName,
            'agent' => $this->agent,
            'provider' => $this->provider,
            'model' => $this->model,
            'finish_reason' => $this->finishReason,
            'tokens_input' => $this->tokens->input,
            'tokens_output' => $this->tokens->output,
            'tokens_cached' => $this->tokens->cached,
            'tokens_reasoning' => $this->tokens->reasoning,
            'cost_total' => $this->costTotal,
            'currency' => $this->currency,
            'duration_ms' => $this->durationMs,
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
            'tenant_id' => $this->tenantId === null ? null : (string) $this->tenantId,
            'cost_center' => $this->costCenter,
            'delegation_grant_id' => $this->delegationGrantId,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'created_at' => ($this->occurredAt ?? new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        ];
    }
}
