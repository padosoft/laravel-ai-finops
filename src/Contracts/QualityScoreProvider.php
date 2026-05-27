<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Contracts;

/**
 * Supplies a quality score (0..1) per model for cost-aware routing. The host app
 * binds an implementation backed by padosoft/eval-harness; absent that, the
 * NullQualityScoreProvider returns null and routing falls back to cheapest.
 */
interface QualityScoreProvider
{
    public function scoreFor(string $model): ?float;

    /** @return array<string,float> model => score */
    public function all(): array;
}
