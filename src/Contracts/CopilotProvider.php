<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

/**
 * Natural-language FinOps copilot. The host binds an implementation backed by
 * laravel-ai-chat / AskMyDocs over the cost data; the default returns a
 * "not configured" response so the endpoint is safe without an LLM wired.
 */
interface CopilotProvider
{
    /**
     * @param  array<string,mixed>  $context
     * @return array{answer: ?string, configured: bool, data?: array<string,mixed>}
     */
    public function answer(string $question, array $context = []): array;
}
