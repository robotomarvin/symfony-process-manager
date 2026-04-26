<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\InputStream;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\IpcMessage;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;
use SymfonyProcessManager\Ipc\WorkerContextInterface;
use SymfonyProcessManager\Ipc\WorkerMetadata;
use SymfonyProcessManager\Transport\ConsumeArgs;
use SymfonyProcessManager\Transport\TransportConfig;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\Worker\WorkerProcessFactoryInterface;

final class ProcessManagerLoop
{
    /** @var list<TransportConfig> */
    private readonly array $resolvedTransportConfigs;

    private int $ticksSinceLastPing = 0;

    /**
     * @param array<string, array{
     *     processes: int,
     *     failure_limit: int,
     *     failure_window: int,
     *     backoff_base: int,
     *     backoff_max: int,
     *     poll_interval_ms: int,
     *     consume_args: array{
     *         memory_limit: int|null,
     *         time_limit: int|null,
     *         limit: int|null,
     *         sleep: int|null,
     *         queues: list<string>,
     *         extra: list<string>,
     *     },
     * }> $transportConfigs
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactoryInterface $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
        array $transportConfigs,
        private readonly MetricsRegistry $metrics,
        private readonly IpcFanout $ipcFanout,
        private readonly WorkerContextInterface $workerContext,
        private readonly ?int $shutdownTimeoutSeconds = 30,
        private readonly int $pingIntervalTicks = 50,
    ) {
        $this->resolvedTransportConfigs = self::buildTransportConfigs($transportConfigs);
    }

    /**
     * @param array<string, array{
     *     processes: int,
     *     failure_limit: int,
     *     failure_window: int,
     *     backoff_base: int,
     *     backoff_max: int,
     *     poll_interval_ms: int,
     *     consume_args: array{
     *         memory_limit: int|null,
     *         time_limit: int|null,
     *         limit: int|null,
     *         sleep: int|null,
     *         queues: list<string>,
     *         extra: list<string>,
     *     },
     * }> $transports
     * @return list<TransportConfig>
     */
    private static function buildTransportConfigs(array $transports): array
    {
        $configs = [];

        foreach ($transports as $name => $transport) {
            $consumeArgs = $transport['consume_args'];

            $configs[] = TransportConfig::create(
                transport: $name,
                processes: $transport['processes'],
                failureLimit: $transport['failure_limit'],
                failureWindowSeconds: $transport['failure_window'],
                backoffBaseSeconds: $transport['backoff_base'],
                backoffMaxSeconds: $transport['backoff_max'],
                pollIntervalMs: $transport['poll_interval_ms'],
                consumeArgs: ConsumeArgs::create(
                    memoryLimit: $consumeArgs['memory_limit'],
                    timeLimit: $consumeArgs['time_limit'],
                    limit: $consumeArgs['limit'],
                    sleep: $consumeArgs['sleep'],
                    queues: $consumeArgs['queues'],
                    extra: $consumeArgs['extra'],
                ),
            );
        }

        return $configs;
    }

    public function run(LoopInterface $loop): int
    {
        $shutdownState = new ShutdownState();
        $workers = $this->initializeWorkers();
        $totalWorkerCount = count($workers);

        $this->logger->info('Process manager server started.', [
            'workers' => $totalWorkerCount,
            'transports' => array_map(
                static fn(TransportConfig $config): string => $config->transport,
                $this->resolvedTransportConfigs,
            ),
        ]);

        $loop->addSignal(SIGTERM, function () use ($shutdownState): void {
            $shutdownState->request(ShutdownReason::SIGNAL, (float) $this->clock->now()->format('U.u'));
        });

        $intervalSec = $this->getMinPollIntervalMicroseconds() / 1_000_000;
        $exitCode = Command::SUCCESS;

        $loop->addPeriodicTimer($intervalSec, function (TimerInterface $timer) use ($loop, &$workers, $shutdownState, &$exitCode): void {
            $result = $this->tick($workers, $shutdownState);

            if ($result !== null) {
                $exitCode = $result;
                $loop->cancelTimer($timer);
                $loop->stop();
            }
        });

        $loop->run();

        return $exitCode;
    }

    /**
     * @param list<array{WorkerState, TransportConfig}> $workers
     */
    public function tick(array &$workers, ShutdownState $shutdownState): ?int
    {
        $this->metrics->setGauge('process_manager_running', $shutdownState->isRequested() ? 0.0 : 1.0, 'Whether the process manager is running');

        $now = (float) $this->clock->now()->format('U.u');

        foreach ($workers as [$worker, $config]) {
            if (!$shutdownState->isRequested() && $worker->shouldStart($now)) {
                $this->startWorker($worker, $config);
                continue;
            }

            if (!$worker->hasProcess()) {
                continue;
            }

            if ($worker->isRunning()) {
                $this->dispatchIpcMessages($worker, $config, $now);
                $this->handleRunningWorker($worker, $shutdownState);
                continue;
            }

            $this->handleWorkerExit($worker, $config, $shutdownState, $now);
        }

        if (!$shutdownState->isRequested()) {
            $this->maybeSendPing();
        }

        if ($shutdownState->isRequested() && !$shutdownState->isSigkillSent()) {
            $this->escalateToSigkill($workers, $shutdownState, $now);
        }

        if ($shutdownState->isRequested() && $this->allWorkersStopped($workers)) {
            $this->logger->info('Process manager shutting down.', [
                'reason' => $shutdownState->getReason()?->value ?? 'completed',
            ]);
            return Command::SUCCESS;
        }

        return null;
    }

    /**
     * @return list<array{WorkerState, TransportConfig}>
     */
    public function initializeWorkers(): array
    {
        $workers = [];
        $workerId = 1;

        foreach ($this->resolvedTransportConfigs as $config) {
            for ($i = 0; $i < $config->processes; $i++) {
                $workers[] = [WorkerState::create($workerId), $config];
                $workerId++;
            }
        }

        return $workers;
    }

    private function startWorker(WorkerState $worker, TransportConfig $config): void
    {
        $process = $this->processFactory->create(
            $config->transport,
            $config->consumeArgs,
        );

        $inputStream = new InputStream();
        $process->setInput($inputStream);

        $worker->setProcess($process);
        $worker->setInputStream($inputStream);
        $this->ipcFanout->register($worker->id, $inputStream);

        $workerId = $worker->id;
        $process->start(function (string $type, string $buffer) use ($workerId): void {
            $this->outputHandler->handleOutput($workerId, $type, $buffer);
        });
        $worker->markStarted();
        $this->metrics->incrementCounter('worker_starts', 'Total number of worker starts', ['transport' => $config->transport]);
        $this->logger->info('Worker started.', [
            'worker' => $worker->id,
            'transport' => $config->transport,
            'pid' => $process->getPid(),
        ]);
    }

    private function handleRunningWorker(WorkerState $worker, ShutdownState $shutdownState): void
    {
        if ($shutdownState->isRequested() && !$worker->isStopSignalSent()) {
            $worker->markStopSignalSent();
            $worker->getProcess()->signal(SIGTERM);
            $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
        }
    }

    private function handleWorkerExit(
        WorkerState $worker,
        TransportConfig $config,
        ShutdownState $shutdownState,
        float $now,
    ): void {
        $exitCode = $worker->getProcess()->getExitCode();
        $pid = $worker->getProcess()->getPid();
        $this->outputHandler->flush($worker->id);
        $this->ipcFanout->unregister($worker->id);
        $worker->getInputStream()?->close();
        $worker->clearInputStream();
        $worker->clearProcess();

        $exitCode = $exitCode ?? 1;
        $this->logger->info('Worker exited.', [
            'worker' => $worker->id,
            'transport' => $config->transport,
            'pid' => $pid,
            'exit_code' => $exitCode,
        ]);
        $this->metrics->incrementCounter('worker_exits', 'Total number of worker exits', ['exit_code' => (string) $exitCode]);

        if ($shutdownState->isRequested()) {
            $worker->markStopped();
            return;
        }

        if ($exitCode === 0) {
            $worker->clearFailures();
            $worker->scheduleImmediateRestart($now);
            $this->logger->info('Worker restarting after expected exit.', ['worker' => $worker->id]);
            return;
        }

        $worker->recordFailure($now, $config->failureWindowSeconds);
        $this->metrics->incrementCounter('worker_failures', 'Total number of worker failures', ['transport' => $config->transport]);
        $failureCount = $worker->getFailureCount();

        if ($failureCount > $config->failureLimit) {
            $this->logger->error('Worker failure limit reached.', [
                'worker' => $worker->id,
                'transport' => $config->transport,
            ]);
            $shutdownState->request(ShutdownReason::FAILURE_LIMIT, $now);
            $worker->markStopped();
            return;
        }

        $delaySeconds = min(
            $config->backoffBaseSeconds * (2 ** ($failureCount - 1)),
            $config->backoffMaxSeconds,
        );

        $worker->scheduleRestart($now, $delaySeconds);
        $this->metrics->incrementCounter('worker_backoffs', 'Total number of worker backoffs', ['transport' => $config->transport]);
        $this->logger->warning('Worker restarting after unexpected exit.', [
            'worker' => $worker->id,
            'delay_seconds' => $delaySeconds,
            'attempt' => $failureCount,
        ]);
    }

    private function dispatchIpcMessages(WorkerState $worker, TransportConfig $config, float $now): void
    {
        $messages = $this->outputHandler->getAndClearIpcMessages($worker->id);

        foreach ($messages as $message) {
            $this->workerContext->setCurrent(new WorkerMetadata($worker->id, $config->transport));

            try {
                $this->handleIpcMessage($message, $worker, $config, $now);
            } finally {
                $this->workerContext->clear();
            }
        }
    }

    private function handleIpcMessage(IpcMessage $message, WorkerState $worker, TransportConfig $config, float $now): void
    {
        if ($message instanceof PongMessage) {
            $worker->setLastPongAt($now);
            $this->metrics->setGauge(
                'worker_last_pong_timestamp',
                $now,
                'Timestamp of last pong received from worker',
                ['worker' => (string) $worker->id],
            );
            $this->logger->debug('Pong received.', ['worker' => $worker->id]);

            return;
        }

        if ($message instanceof ProcessedCommandMessage && $message->status === 'handled') {
            $this->metrics->incrementCounter('messages_processed', 'Total messages processed', ['transport' => $config->transport]);
        }
    }

    private function maybeSendPing(): void
    {
        $this->ticksSinceLastPing++;

        if ($this->ticksSinceLastPing >= $this->pingIntervalTicks) {
            $this->ticksSinceLastPing = 0;
            $this->ipcFanout->send(new PingMessage());
        }
    }

    /**
     * @param list<array{WorkerState, TransportConfig}> $workers
     */
    private function escalateToSigkill(array $workers, ShutdownState $shutdownState, float $now): void
    {
        if ($this->shutdownTimeoutSeconds === null) {
            return;
        }

        $requestedAt = $shutdownState->getRequestedAt();
        if ($requestedAt === null) {
            return;
        }

        if ($now - $requestedAt < $this->shutdownTimeoutSeconds) {
            return;
        }

        $shutdownState->markSigkillSent();

        foreach ($workers as [$worker]) {
            if (!$worker->isRunning()) {
                continue;
            }

            $worker->getProcess()->signal(SIGKILL);
            $this->metrics->incrementCounter('worker_sigkills', 'Total number of SIGKILLs sent to workers');
            $this->logger->warning('Sent SIGKILL to worker after shutdown timeout.', [
                'worker' => $worker->id,
                'timeout_seconds' => $this->shutdownTimeoutSeconds,
            ]);
        }
    }

    /**
     * @param list<array{WorkerState, TransportConfig}> $workers
     */
    private function allWorkersStopped(array $workers): bool
    {
        foreach ($workers as [$worker]) {
            if ($worker->hasProcess()) {
                return false;
            }
        }

        return true;
    }

    private function getMinPollIntervalMicroseconds(): int
    {
        $minMs = PHP_INT_MAX;

        foreach ($this->resolvedTransportConfigs as $config) {
            $minMs = min($minMs, $config->pollIntervalMs);
        }

        return $minMs * 1000;
    }
}
