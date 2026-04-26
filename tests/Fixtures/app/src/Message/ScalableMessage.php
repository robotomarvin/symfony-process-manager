<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Message;

final class ScalableMessage
{
    public function __construct(public readonly float $sleepSeconds) {}
}
