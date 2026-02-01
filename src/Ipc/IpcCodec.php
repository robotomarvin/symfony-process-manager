<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc;

final class IpcCodec
{
    public const PREFIX = '@spm:';

    /** @var array<class-string<IpcMessage>, true> */
    private array $allowedTypes = [];

    /**
     * @param list<class-string<IpcMessage>> $allowedTypes
     */
    public function __construct(array $allowedTypes = [])
    {
        foreach ($allowedTypes as $type) {
            $this->allowedTypes[$type] = true;
        }
    }

    public function encode(IpcMessage $message): string
    {
        $envelope = [
            'type' => $message::class,
            'payload' => $message->toArray(),
        ];

        return self::PREFIX . json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function decode(string $line): ?IpcMessage
    {
        if (!str_starts_with($line, self::PREFIX)) {
            return null;
        }

        $json = substr($line, strlen(self::PREFIX));

        try {
            $envelope = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($envelope)) {
            return null;
        }

        $type = $envelope['type'] ?? null;
        $payload = $envelope['payload'] ?? [];

        if (!is_string($type) || !is_array($payload)) {
            return null;
        }

        if ($this->allowedTypes !== [] && !isset($this->allowedTypes[$type])) {
            return null;
        }

        if (!class_exists($type)) {
            return null;
        }

        if (!is_a($type, IpcMessage::class, true)) {
            return null;
        }

        return $type::fromArray($payload);
    }

    public static function isIpcLine(string $line): bool
    {
        return str_starts_with($line, self::PREFIX);
    }
}
