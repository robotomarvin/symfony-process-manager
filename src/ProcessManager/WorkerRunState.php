<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

enum WorkerRunState: string
{
    case Idle = 'idle';
    case Busy = 'busy';
}
