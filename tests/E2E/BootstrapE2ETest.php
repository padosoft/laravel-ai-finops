<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\E2E;

use Padosoft\LaravelAiFinOps\Tests\TestCase;

/**
 * End-to-end suite placeholder. Real E2E flows (metering a laravel/ai call,
 * budget block returning 402, pricing sync) land with M1+.
 */
class BootstrapE2ETest extends TestCase
{
    public function test_application_with_package_responds_to_a_basic_request(): void
    {
        $this->app['router']->get('/_ai_finops_probe', fn () => response()->json(['ok' => true]));

        $this->get('/_ai_finops_probe')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
