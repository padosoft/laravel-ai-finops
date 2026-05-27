<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

use Padosoft\LaravelAiFinOps\Data\AiCallEnvelope;

/**
 * Pre-flight guardrail check. The host binds an implementation backed by
 * padosoft/laravel-pii-redactor or laravel-ai-act-compliance; it returns a
 * violation reason to block the (costly) call, or null to allow.
 */
interface GuardrailProvider
{
    public function violation(AiCallEnvelope $envelope): ?string;
}
