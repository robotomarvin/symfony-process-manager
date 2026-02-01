<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Ipc;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\InputStream;
use SymfonyProcessManager\Ipc\Filter\WorkerFilterInterface;

final class IpcFanout
{
    /** @var array<int, InputStream> */
    private array $streams = [];

    public function __construct(
        private readonly IpcCodec $codec,
        private readonly LoggerInterface $logger,
    ) {}

    public function register(int $workerId, InputStream $stream): void
    {
        $this->streams[$workerId] = $stream;
    }

    public function unregister(int $workerId): void
    {
        unset($this->streams[$workerId]);
    }

    /**
     * @param list<WorkerFilterInterface> $filters
     */
    public function send(IpcMessage $message, array $filters = []): void
    {
        $encoded = $this->codec->encode($message) . "\n";

        foreach ($this->streams as $workerId => $stream) {
            if (!$this->matchesFilters($workerId, $filters)) {
                continue;
            }

            if ($stream->isClosed()) {
                continue;
            }

            try {
                $stream->write($encoded);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to write IPC message to worker.', [
                    'worker' => $workerId,
                    'message_type' => $message::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<WorkerFilterInterface> $filters
     */
    private function matchesFilters(int $workerId, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!$filter->matches($workerId)) {
                return false;
            }
        }

        return true;
    }
}
