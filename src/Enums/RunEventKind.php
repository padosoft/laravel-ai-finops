<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

/**
 * What a run-event row describes. A run is a sequence of generation steps, and
 * between them the tools the model asked for.
 */
enum RunEventKind: string
{
    /** One generation step: a provider call, its usage, and how it finished. */
    case Step = 'step';

    /** One tool invocation the model asked for during a step. */
    case Tool = 'tool';
}
