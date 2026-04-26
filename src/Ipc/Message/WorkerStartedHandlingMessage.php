<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc\Message;

use SymfonyProcessManager\Ipc\IpcMessage;

final readonly class WorkerStartedHandlingMessage implements IpcMessage
{
    public function __construct(public string $command) {}

    public function toArray(): array
    {
        return ['command' => $this->command];
    }

    public static function fromArray(array $data): static
    {
        $command = $data['command'] ?? '';

        return new static(command: is_string($command) ? $command : '');
    }
}
