<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Padosoft\LaravelAiFinOps\Models\AuditEntry;
use Throwable;

/**
 * Records created/updated/deleted events for governance models into the audit log.
 * Secret-ish channel config is not audited verbatim (only changed keys are recorded).
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        $this->record('created', $model, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $this->record('updated', $model, $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $this->record('deleted', $model, []);
    }

    /** @param array<string,mixed> $changes */
    private function record(string $event, Model $model, array $changes): void
    {
        if (! config('ai-finops.audit.enabled', true)) {
            return;
        }

        try {
            AuditEntry::create([
                'event' => $event,
                'subject_type' => class_basename($model),
                'subject_id' => (string) ($model->getKey() ?? ''),
                'actor' => $this->actor(),
                'changes' => $this->redact($changes),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never let auditing break the primary operation.
        }
    }

    private function actor(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }

    /**
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function redact(array $changes): array
    {
        foreach (['config', 'secret', 'api_key', 'token', 'webhook'] as $sensitive) {
            if (array_key_exists($sensitive, $changes)) {
                $changes[$sensitive] = '[redacted]';
            }
        }

        return $changes;
    }
}
