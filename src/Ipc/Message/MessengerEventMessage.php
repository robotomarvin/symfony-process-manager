<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc\Message;

use SymfonyProcessManager\Ipc\IpcMessage;

final readonly class MessengerEventMessage implements IpcMessage
{
    public const EVENT_RECEIVED = 'received';
    public const EVENT_HANDLED = 'handled';
    public const EVENT_FAILED = 'failed';
    public const EVENT_RETRIED = 'retried';

    public function __construct(
        public string $event,
        public string $command,
        public string $transport,
        public ?float $durationSeconds = null,
        public ?string $errorClass = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'event' => $this->event,
            'command' => $this->command,
            'transport' => $this->transport,
        ];

        if ($this->durationSeconds !== null) {
            $data['duration_seconds'] = $this->durationSeconds;
        }

        if ($this->errorClass !== null) {
            $data['error_class'] = $this->errorClass;
        }

        return $data;
    }

    public static function fromArray(array $data): static
    {
        $event = $data['event'] ?? '';
        $command = $data['command'] ?? '';
        $transport = $data['transport'] ?? '';
        $duration = $data['duration_seconds'] ?? null;
        $errorClass = $data['error_class'] ?? null;

        return new static(
            event: is_string($event) ? $event : '',
            command: is_string($command) ? $command : '',
            transport: is_string($transport) ? $transport : '',
            durationSeconds: is_float($duration) || is_int($duration) ? (float) $duration : null,
            errorClass: is_string($errorClass) ? $errorClass : null,
        );
    }
}
