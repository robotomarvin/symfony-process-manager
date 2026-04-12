<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Output;

use Symfony\Component\Process\Process;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcMessage;

final class WorkerOutputHandler
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $buffers = [];

    /** @var array<int, list<IpcMessage>> */
    private array $ipcQueues = [];

    /** @var resource */
    private mixed $stdoutStream;

    /** @var resource */
    private mixed $stderrStream;

    /**
     * @param resource|null $stdoutStream
     * @param resource|null $stderrStream
     */
    public function __construct(
        private readonly WorkerOutputFormatter $formatter,
        private readonly IpcCodec $ipcCodec,
        mixed $stdoutStream = null,
        mixed $stderrStream = null,
    ) {
        $this->stdoutStream = $stdoutStream ?? \STDOUT;
        $this->stderrStream = $stderrStream ?? \STDERR;

        assert(\is_resource($this->stdoutStream), 'stdoutStream must be a resource');
        assert(\is_resource($this->stderrStream), 'stderrStream must be a resource');
    }

    public function handleOutput(int $workerId, string $type, string $buffer): void
    {
        $this->initialize($workerId);
        $this->buffers[$workerId][$type] .= $buffer;

        while (($newlinePosition = strpos($this->buffers[$workerId][$type], "\n")) !== false) {
            $line = substr($this->buffers[$workerId][$type], 0, $newlinePosition);
            $this->buffers[$workerId][$type] = substr(
                $this->buffers[$workerId][$type],
                $newlinePosition + 1,
            );
            $line = rtrim($line, "\r");
            $this->forwardLine($workerId, $type, $line);
        }
    }

    public function flush(int $workerId): void
    {
        if (!isset($this->buffers[$workerId])) {
            return;
        }

        foreach ([Process::OUT, Process::ERR] as $type) {
            $buffer = $this->buffers[$workerId][$type];

            if ($buffer === '') {
                continue;
            }

            $line = rtrim($buffer, "\r\n");

            if ($line !== '') {
                $this->forwardLine($workerId, $type, $line);
            }

            $this->buffers[$workerId][$type] = '';
        }
    }

    private function initialize(int $workerId): void
    {
        if (!isset($this->buffers[$workerId])) {
            $this->buffers[$workerId] = [
                Process::OUT => '',
                Process::ERR => '',
            ];
        }
    }

    /**
     * @return list<IpcMessage>
     */
    public function getAndClearIpcMessages(int $workerId): array
    {
        $messages = $this->ipcQueues[$workerId] ?? [];
        $this->ipcQueues[$workerId] = [];

        return $messages;
    }

    private function forwardLine(int $workerId, string $type, string $line): void
    {
        if (IpcCodec::isIpcLine($line)) {
            $message = $this->ipcCodec->decode($line);

            if ($message !== null) {
                $this->ipcQueues[$workerId][] = $message;
            }

            return;
        }

        $payload = $this->formatter->format($workerId, $line);
        $stream = $type === Process::ERR ? $this->stderrStream : $this->stdoutStream;
        fwrite($stream, $payload . PHP_EOL);
        fflush($stream);
    }
}
