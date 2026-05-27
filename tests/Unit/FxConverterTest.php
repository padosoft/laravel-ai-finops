<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Tests\Unit;

use Padosoft\LaravelAiFinOps\Support\FxConverter;
use Padosoft\LaravelAiFinOps\Tests\TestCase;

class FxConverterTest extends TestCase
{
    public function test_same_currency_is_identity(): void
    {
        $fx = $this->app->make(FxConverter::class);

        $this->assertSame(1.0, $fx->rate('USD', 'USD'));
        $this->assertSame(42.0, $fx->convert(42.0, 'EUR', 'EUR'));
    }

    public function test_no_provider_falls_back_to_one_to_one(): void
    {
        $fx = $this->app->make(FxConverter::class);

        $this->assertSame(1.0, $fx->rate('USD', 'EUR'));
        $this->assertSame(10.0, $fx->convert(10.0, 'USD', 'EUR'));
    }

    public function test_uses_configured_callable_provider(): void
    {
        config(['ai-finops.currency.fx_provider' => fn (string $from, string $to) => $from === 'USD' && $to === 'EUR' ? 0.9 : 1.0]);

        $fx = $this->app->make(FxConverter::class);

        $this->assertSame(0.9, $fx->rate('USD', 'EUR'));
        $this->assertSame(9.0, $fx->convert(10.0, 'USD', 'EUR'));
    }
}
