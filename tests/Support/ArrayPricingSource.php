<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Support;

use Padosoft\LaravelAiFinOps\Contracts\PricingSource;

/** In-memory PricingSource for tests (no network). */
class ArrayPricingSource implements PricingSource
{
    public int $syncs = 0;

    /**
     * @param  array<string,array<string,mixed>>  $models
     */
    public function __construct(
        private array $models = [],
        private string $name = 'litellm',
        private ?\DateTimeInterface $syncedAt = null,
    ) {}

    public function all(): array
    {
        return $this->models;
    }

    public function sync(): int
    {
        $this->syncs++;

        return count($this->models);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function syncedAt(): ?\DateTimeInterface
    {
        return $this->syncedAt;
    }
}
