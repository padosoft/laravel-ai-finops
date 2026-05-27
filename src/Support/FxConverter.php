<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Converts amounts between currencies. Uses the configured fx_provider
 * (callable|class-string returning a rate for [from, to]); falls back to 1:1 when
 * no provider is set or the same currency is requested.
 */
class FxConverter
{
    public function __construct(private readonly Config $config) {}

    public function rate(string $from, string $to): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        $provider = $this->config->get('ai-finops.currency.fx_provider');

        if (is_string($provider) && class_exists($provider)) {
            $provider = app($provider);
        }

        if (is_callable($provider)) {
            $rate = $provider($from, $to);

            if (is_numeric($rate) && $rate > 0) {
                return (float) $rate;
            }
        }

        return 1.0;
    }

    public function convert(float $amount, string $from, string $to): float
    {
        return round($amount * $this->rate($from, $to), 8);
    }
}
