<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc;

final class IpcEndpoint
{
    /** @var resource */
    private mixed $stream;

    /**
     * @param resource|null $stream Stream to write IPC frames to. Defaults to STDOUT.
     */
    public function __construct(
        private readonly IpcCodec $codec,
        mixed $stream = null,
    ) {
        $this->stream = $stream ?? \STDOUT;

        assert(\is_resource($this->stream), 'stream must be a resource');
    }

    public function send(IpcMessage $message): void
    {
        $encoded = $this->codec->encode($message);
        fwrite($this->stream, $encoded . "\n");
        fflush($this->stream);
    }
}
