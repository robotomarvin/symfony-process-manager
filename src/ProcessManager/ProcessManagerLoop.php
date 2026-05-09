<?php

declare(strict_types=1);

namespace SymfonyProcessManager\ProcessManager;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\InputStream;
use SymfonyProcessManager\Consumer\ConsumerConfig;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\IpcMessage;
use SymfonyProcessManager\Ipc\Message\MessengerEventMessage;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;
use SymfonyProcessManager\Ipc\WorkerContextInterface;
use SymfonyProcessManager\Ipc\WorkerMetadata;
use SymfonyProcessManager\Metrics\MessageClassResolver;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\Worker\WorkerProcessFactoryInterface;

final class ProcessManagerLoop
{
    public const DEFAULT_DURATION_BUCKETS = [0.01, 0.05, 0.1, 0.5, 1.0, 5.0, 10.0, 30.0, 60.0];

    private int $ticksSinceLastPing = 0;

    /** @var array<string, int> */
    private array $inFlight = [];

    /** @var list<float> */
    private readonly array $durationBuckets;

    /**
     * @param list<WorkerPool> $pools
     * @param list<float|int> $durationBuckets
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ShutdownState $shutdownState,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly WorkerProcessFactoryInterface $processFactory,
        private readonly WorkerOutputHandler $outputHandler,
        private readonly array $pools,
        private readonly MetricsRegistry $metrics,
        private readonly IpcFanout $ipcFanout,
        private readonly WorkerContextInterface $workerContext,
        private readonly MessageClassResolver $messageClassResolver,
        private readonly bool $messagesMetricsEnabled = true,
        array $durationBuckets = self::DEFAULT_DURATION_BUCKETS,
        private readonly ?int $shutdownTimeoutSeconds = 30,
        private readonly int $pingIntervalTicks = 50,
    ) {
        $this->durationBuckets = array_values(array_map(static fn(float|int $b): float => (float) $b, $durationBuckets));
    }

    public function start(): void
    {
        $totalWorkers = 0;
        $consumers = [];
        foreach ($this->pools as $pool) {
            $totalWorkers += $pool->activeWorkerCount();
            $consumers[$pool->label()] = $pool->transports();
        }

        $this->logger->info('Process manager server started.', [
            'workers' => $totalWorkers,
            'consumers' => $consumers,
        ]);

        $intervalSec = $this->getMinPollIntervalMicroseconds() / 1_000_000;

        $this->loop->addPeriodicTimer($intervalSec, function () use ($intervalSec): void {
            unset($intervalSec);
            $result = $this->tick();

            if ($result !== null) {
                $this->shutdownState->setExitCode($result);
                $this->loop->stop();
            }
        });
    }

    public function tick(): ?int
    {
        $this->metrics->setGauge('process_manager_running', $this->shutdownState->isRequested() ? 0.0 : 1.0, 'Whether the process manager is running');

        $now = (float) $this->clock->now()->format('U.u');

        if ($this->shutdownState->isRequested()) {
            $this->ensureAllPoolsDraining();
        }

        foreach ($this->pools as $pool) {
            $this->tickPool($pool, $now);
            $pool->reapDrained();
        }

        if (!$this->shutdownState->isRequested()) {
            $this->maybeSendPing();
        }

        if ($this->shutdownState->isRequested() && !$this->shutdownState->isSigkillSent()) {
            $this->escalateToSigkill($now);
        }

        if ($this->shutdownState->isRequested() && $this->allWorkersStopped()) {
            $this->logger->info('Process manager shutting down.', [
                'reason' => $this->shutdownState->getReason()?->value ?? 'completed',
            ]);
            return Command::SUCCESS;
        }

        return null;
    }

    private function tickPool(WorkerPool $pool, float $now): void
    {
        foreach ($pool->workers() as $worker) {
            $this->tickWorker($worker, $pool, $now, draining: false);
        }

        foreach ($pool->drainingWorkers() as $worker) {
            $this->tickWorker($worker, $pool, $now, draining: true);
        }
    }

    private function tickWorker(WorkerState $worker, WorkerPool $pool, float $now, bool $draining): void
    {
        $config = $pool->config;

        if (!$draining && !$this->shutdownState->isRequested() && $worker->shouldStart($now)) {
            $this->startWorker($worker, $config);
            return;
        }

        if (!$worker->hasProcess()) {
            return;
        }

        if ($worker->isRunning()) {
            $this->dispatchIpcMessages($worker, $pool, $now);

            if ($draining || $this->shutdownState->isRequested()) {
                $this->sendStopSignal($worker);
            }

            return;
        }

        $this->handleWorkerExit($worker, $config, $now, $draining);
    }

    private function startWorker(WorkerState $worker, ConsumerConfig $config): void
    {
        $process = $this->processFactory->create(
            $config->transports,
            $config->consumeArgs,
        );

        $inputStream = new InputStream();
        $process->setInput($inputStream);

        $worker->setProcess($process);
        $worker->setInputStream($inputStream);
        $this->ipcFanout->register($worker->id, $inputStream);
        $this->outputHandler->registerWorker($worker->id, $config->label);

        $workerId = $worker->id;
        $process->start(function (string $type, string $buffer) use ($workerId): void {
            $this->outputHandler->handleOutput($workerId, $type, $buffer);
        });
        $worker->markStarted();

        $this->metrics->incrementCounter(
            'worker_starts',
            'Total number of worker starts',
            ['consumer' => $config->label],
        );

        $this->logger->info('Worker started.', [
            'worker' => $worker->id,
            'consumer' => $config->label,
            'transports' => $config->transports,
            'pid' => $process->getPid(),
        ]);
    }

    private function sendStopSignal(WorkerState $worker): void
    {
        if ($worker->isStopSignalSent()) {
            return;
        }

        $worker->markStopSignalSent();
        $worker->getProcess()->signal(SIGTERM);
        $this->logger->info('Sent SIGTERM to worker.', ['worker' => $worker->id]);
    }

    private function handleWorkerExit(
        WorkerState $worker,
        ConsumerConfig $config,
        float $now,
        bool $draining,
    ): void {
        $exitCode = $worker->getProcess()->getExitCode();
        $pid = $worker->getProcess()->getPid();
        $this->outputHandler->flush($worker->id);
        $this->outputHandler->unregisterWorker($worker->id);
        $this->ipcFanout->unregister($worker->id);
        $worker->getInputStream()?->close();
        $worker->clearInputStream();
        $worker->clearProcess();

        $this->metrics->removeGauge('worker_last_pong_timestamp', ['worker' => (string) $worker->id]);
        foreach ($config->transports as $transport) {
            $this->metrics->removeGauge(
                'worker_busy',
                ['worker' => (string) $worker->id, 'consumer' => $config->label, 'transport' => $transport],
            );
        }

        $exitCode = $exitCode ?? 1;
        $this->logger->info('Worker exited.', [
            'worker' => $worker->id,
            'consumer' => $config->label,
            'pid' => $pid,
            'exit_code' => $exitCode,
        ]);
        $this->metrics->incrementCounter(
            'worker_exits',
            'Total number of worker exits',
            ['consumer' => $config->label, 'exit_code' => (string) $exitCode],
        );

        if ($draining || $this->shutdownState->isRequested()) {
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
        $this->metrics->incrementCounter(
            'worker_failures',
            'Total number of worker failures',
            ['consumer' => $config->label],
        );
        $failureCount = $worker->getFailureCount();

        if ($failureCount > $config->failureLimit) {
            $this->logger->error('Worker failure limit reached.', [
                'worker' => $worker->id,
                'consumer' => $config->label,
            ]);
            $this->shutdownState->request(ShutdownReason::FAILURE_LIMIT, $now);
            $worker->markStopped();
            return;
        }

        $delaySeconds = min(
            $config->backoffBaseSeconds * (2 ** ($failureCount - 1)),
            $config->backoffMaxSeconds,
        );

        $worker->scheduleRestart($now, $delaySeconds);
        $this->metrics->incrementCounter(
            'worker_backoffs',
            'Total number of worker backoffs',
            ['consumer' => $config->label],
        );
        $this->logger->warning('Worker restarting after unexpected exit.', [
            'worker' => $worker->id,
            'delay_seconds' => $delaySeconds,
            'attempt' => $failureCount,
        ]);
    }

    private function dispatchIpcMessages(WorkerState $worker, WorkerPool $pool, float $now): void
    {
        $messages = $this->outputHandler->getAndClearIpcMessages($worker->id);

        foreach ($messages as $message) {
            $this->workerContext->setCurrent(new WorkerMetadata($worker->id, $pool->label()));

            try {
                $this->handleIpcMessage($message, $worker, $pool, $now);
            } finally {
                $this->workerContext->clear();
            }
        }
    }

    private function handleIpcMessage(IpcMessage $message, WorkerState $worker, WorkerPool $pool, float $now): void
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

        if ($message instanceof MessengerEventMessage) {
            $this->handleMessengerEvent($message, $worker, $pool);
        }
    }

    private function handleMessengerEvent(
        MessengerEventMessage $message,
        WorkerState $worker,
        WorkerPool $pool,
    ): void {
        $config = $pool->config;
        $busyLabels = [
            'worker' => (string) $worker->id,
            'consumer' => $config->label,
            'transport' => $message->transport,
        ];

        switch ($message->event) {
            case MessengerEventMessage::EVENT_RECEIVED:
                $worker->markBusy();
                $this->metrics->setGauge('worker_busy', 1.0, 'Whether worker is currently busy', $busyLabels);
                break;
            case MessengerEventMessage::EVENT_HANDLED:
            case MessengerEventMessage::EVENT_FAILED:
                $worker->markIdle();
                $this->metrics->setGauge('worker_busy', 0.0, 'Whether worker is currently busy', $busyLabels);
                $pool->recordMessageProcessed($message->transport);
                break;
        }

        if ($message->event === MessengerEventMessage::EVENT_HANDLED) {
            $this->metrics->incrementCounter(
                'messages_processed',
                'Total messages processed',
                ['consumer' => $config->label, 'transport' => $message->transport],
            );
        }

        if (!$this->messagesMetricsEnabled) {
            return;
        }

        $messageClass = $this->messageClassResolver->resolve($message->command);
        $labels = ['transport' => $message->transport, 'message_class' => $messageClass];

        switch ($message->event) {
            case MessengerEventMessage::EVENT_RECEIVED:
                $this->changeInFlight($message->transport, +1);
                break;
            case MessengerEventMessage::EVENT_HANDLED:
                $this->metrics->incrementCounter(
                    'messenger_messages_processed',
                    'Total messenger messages handled successfully',
                    $labels,
                );
                $this->observeDuration($message, $labels);
                $this->changeInFlight($message->transport, -1);
                break;
            case MessengerEventMessage::EVENT_FAILED:
                $this->metrics->incrementCounter(
                    'messenger_messages_failed',
                    'Total messenger messages that failed handling',
                    $labels,
                );
                $this->observeDuration($message, $labels);
                $this->changeInFlight($message->transport, -1);
                break;
            case MessengerEventMessage::EVENT_RETRIED:
                $this->metrics->incrementCounter(
                    'messenger_messages_retried',
                    'Total messenger messages scheduled for retry',
                    $labels,
                );
                break;
        }
    }

    /**
     * @param array<string, string> $labels
     */
    private function observeDuration(MessengerEventMessage $message, array $labels): void
    {
        if ($message->durationSeconds === null) {
            return;
        }

        $this->metrics->observeHistogram(
            'messenger_message_duration_seconds',
            $message->durationSeconds,
            'Messenger message handling duration in seconds',
            $this->durationBuckets,
            $labels,
        );
    }

    private function changeInFlight(string $transport, int $delta): void
    {
        $current = max(0, ($this->inFlight[$transport] ?? 0) + $delta);
        $this->inFlight[$transport] = $current;
        $this->metrics->setGauge(
            'messenger_messages_in_flight',
            (float) $current,
            'Messenger messages currently in flight per transport',
            ['transport' => $transport],
        );
    }


    private function maybeSendPing(): void
    {
        $this->ticksSinceLastPing++;

        if ($this->ticksSinceLastPing >= $this->pingIntervalTicks) {
            $this->ticksSinceLastPing = 0;
            $this->ipcFanout->send(new PingMessage());
        }
    }

    private function ensureAllPoolsDraining(): void
    {
        foreach ($this->pools as $pool) {
            if ($pool->activeWorkerCount() > 0) {
                $pool->drainAll();
            }
        }
    }

    private function escalateToSigkill(float $now): void
    {
        if ($this->shutdownTimeoutSeconds === null) {
            return;
        }

        $requestedAt = $this->shutdownState->getRequestedAt();
        if ($requestedAt === null) {
            return;
        }

        if ($now - $requestedAt < $this->shutdownTimeoutSeconds) {
            return;
        }

        $this->shutdownState->markSigkillSent();

        foreach ($this->pools as $pool) {
            foreach ($pool->allWorkers() as $worker) {
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
    }

    private function allWorkersStopped(): bool
    {
        foreach ($this->pools as $pool) {
            foreach ($pool->allWorkers() as $worker) {
                if ($worker->hasProcess()) {
                    return false;
                }
            }
        }

        return true;
    }

    private function getMinPollIntervalMicroseconds(): int
    {
        $minMs = PHP_INT_MAX;

        foreach ($this->pools as $pool) {
            $minMs = min($minMs, $pool->config->pollIntervalMs);
        }

        if ($minMs === PHP_INT_MAX) {
            $minMs = 200;
        }

        return $minMs * 1000;
    }
}
