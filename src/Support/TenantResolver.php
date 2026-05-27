<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;

/** Resolves the current tenant id from the configured resolver (callable|class). */
class TenantResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    public function resolve(): string|int|null
    {
        if (! $this->config->get('ai-finops.tenancy.enabled', false)) {
            return null;
        }

        $resolver = $this->config->get('ai-finops.tenancy.resolver');

        if ($resolver === null) {
            return null;
        }

        // Accept a class-string (invokable) even when not explicitly bound — a
        // common Laravel pattern; the container can resolve concrete classes.
        if (is_string($resolver) && ($this->container->bound($resolver) || class_exists($resolver))) {
            $resolver = $this->container->make($resolver);
        }

        if (is_callable($resolver)) {
            $tenant = $resolver();

            return is_string($tenant) || is_int($tenant) ? $tenant : null;
        }

        return null;
    }
}
