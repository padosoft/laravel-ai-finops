<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiFinOps\Enums;

/**
 * How a step or tool ended. Failure is a first-class outcome here: a run that
 * died on its third step still spent the tokens of the first two, and a tool
 * that threw after nine seconds is a different problem from one that threw
 * immediately.
 */
enum RunEventStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
