<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Fixtures\App\Message;

final class MultiTransportMessage
{
    public function __construct(public readonly string $payload) {}
}
