<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Message;

final class FixtureMessage
{
    public function __construct(public readonly string $payload)
    {
    }
}
