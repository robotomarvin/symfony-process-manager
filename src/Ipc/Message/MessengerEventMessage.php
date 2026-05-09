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
        $event = $data['event'] ?? null;
        $command = $data['command'] ?? null;
        $transport = $data['transport'] ?? null;
        $duration = $data['duration_seconds'] ?? null;
        $errorClass = $data['error_class'] ?? null;

        // Reject malformed payloads: returning here causes IpcCodec::decode()
        // to drop the message rather than emit metrics with empty labels,
        // which would leak gauges (worker_busy{transport=""} can never be
        // cleared) and corrupt counters with phantom series.
        if (!is_string($event) || !in_array($event, [self::EVENT_RECEIVED, self::EVENT_HANDLED, self::EVENT_FAILED, self::EVENT_RETRIED], true)) {
            throw new \InvalidArgumentException('MessengerEventMessage requires a known event.');
        }

        if (!is_string($command) || $command === '') {
            throw new \InvalidArgumentException('MessengerEventMessage requires a non-empty command.');
        }

        if (!is_string($transport) || $transport === '') {
            throw new \InvalidArgumentException('MessengerEventMessage requires a non-empty transport.');
        }

        return new static(
            event: $event,
            command: $command,
            transport: $transport,
            durationSeconds: is_float($duration) || is_int($duration) ? (float) $duration : null,
            errorClass: is_string($errorClass) ? $errorClass : null,
        );
    }
}
