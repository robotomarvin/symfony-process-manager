<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc\Message;

use SymfonyProcessManager\Ipc\IpcMessage;

final readonly class ProcessedCommandMessage implements IpcMessage
{
    public function __construct(
        public string $status,
        public string $command,
        public ?string $errorInfo = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'status' => $this->status,
            'command' => $this->command,
        ];

        if ($this->errorInfo !== null) {
            $data['error_info'] = $this->errorInfo;
        }

        return $data;
    }

    public static function fromArray(array $data): static
    {
        $status = $data['status'] ?? '';
        $command = $data['command'] ?? '';
        $errorInfo = $data['error_info'] ?? null;

        return new static(
            status: is_string($status) ? $status : '',
            command: is_string($command) ? $command : '',
            errorInfo: is_string($errorInfo) ? $errorInfo : null,
        );
    }
}
