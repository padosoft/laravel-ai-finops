<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Routing;

use Padosoft\LaravelAiFinOps\Contracts\QualityScoreProvider;

/** Default no-op provider used when eval-harness integration is not wired. */
class NullQualityScoreProvider implements QualityScoreProvider
{
    public function scoreFor(string $model): ?float
    {
        return null;
    }

    public function all(): array
    {
        return [];
    }
}
