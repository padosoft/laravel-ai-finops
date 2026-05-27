<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Guardrails;

use Padosoft\LaravelAiFinOps\Contracts\GuardrailProvider;
use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;

/** Default no-op guardrail used when pii-redactor / ai-act-compliance is not wired. */
class NullGuardrailProvider implements GuardrailProvider
{
    public function violation(AiCallEnvelope $envelope): ?string
    {
        return null;
    }
}
