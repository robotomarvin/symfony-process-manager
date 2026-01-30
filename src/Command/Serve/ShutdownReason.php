<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

enum ShutdownReason: string
{
    case SIGNAL = 'signal';
    case FAILURE_LIMIT = 'failure_limit';
}
