<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

enum ShutdownReason: string
{
    case SIGNAL = 'signal';
    case FAILURE_LIMIT = 'failure_limit';
}
