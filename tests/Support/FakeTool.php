<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A minimal real tool, so the run-observer tests exercise the same name
 * resolution laravel/ai itself uses rather than a mock's generated class name.
 */
class FakeTool implements Tool
{
    public function __construct(private readonly string $name = 'lookup_order') {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): Stringable|string
    {
        return 'Looks an order up by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ok';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
