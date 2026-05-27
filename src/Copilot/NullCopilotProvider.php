<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Copilot;

use Padosoft\LaravelAiFinOps\Contracts\CopilotProvider;

/** Default provider used when no LLM-backed copilot is wired. */
class NullCopilotProvider implements CopilotProvider
{
    public function answer(string $question, array $context = []): array
    {
        return [
            'answer' => null,
            'configured' => false,
            'data' => ['hint' => 'Bind a CopilotProvider (laravel-ai-chat / AskMyDocs) to enable NL answers.'],
        ];
    }
}
