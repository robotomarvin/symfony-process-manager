<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc\Message;

use SymfonyProcessManager\Ipc\IpcMessage;

final readonly class PingMessage implements IpcMessage
{
    public function toArray(): array
    {
        return [];
    }

    public static function fromArray(array $data): static
    {
        return new static();
    }
}
