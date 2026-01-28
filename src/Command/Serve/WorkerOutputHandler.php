<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Command\Serve;

use Symfony\Component\Process\Process;

final class WorkerOutputHandler
{
    /**
     * @var array<int, array<string, string>>
     */
    private array $buffers = [];

    public function __construct(private readonly WorkerOutputFormatter $formatter) {}

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

    private function forwardLine(int $workerId, string $type, string $line): void
    {
        $payload = $this->formatter->format($workerId, $line);
        $stream = $type === Process::ERR ? STDERR : STDOUT;
        fwrite($stream, $payload . PHP_EOL);
        fflush($stream);
    }
}
