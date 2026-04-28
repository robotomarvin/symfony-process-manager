<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Worker;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcEndpoint;
use SymfonyProcessManager\Ipc\Message\MessengerEventMessage;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;

final class WorkerIpcSubscriber implements EventSubscriberInterface
{
    private const DURATION_MAP_MAX = 1024;

    private string $stdinBuffer = '';

    /** @var resource */
    private readonly mixed $stdinStream;

    private bool $stdinReady = false;

    /** @var array<int, float> */
    private array $startedAt = [];

    /**
     * @param resource|null $stdinStream
     */
    public function __construct(
        private readonly IpcEndpoint $endpoint,
        private readonly IpcCodec $codec,
        private readonly ClockInterface $clock,
        mixed $stdinStream = null,
    ) {
        $this->stdinStream = $stdinStream ?? \STDIN;

        assert(\is_resource($this->stdinStream), 'stdinStream must be a resource');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerMessageRetriedEvent::class => 'onMessageRetried',
        ];
    }

    public function onWorkerRunning(): void
    {
        $this->ensureNonBlocking();
        $this->drainStdin();
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $this->rememberStart($envelope);

        $this->endpoint->send(new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_RECEIVED,
            command: self::messageClass($envelope),
            transport: $event->getReceiverName(),
        ));
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $this->endpoint->send(new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_HANDLED,
            command: self::messageClass($envelope),
            transport: $event->getReceiverName(),
            durationSeconds: $this->popDuration($envelope),
        ));
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();

        $this->endpoint->send(new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_FAILED,
            command: self::messageClass($envelope),
            transport: $event->getReceiverName(),
            durationSeconds: $this->popDuration($envelope),
            errorClass: $event->getThrowable()::class,
        ));
    }

    public function onMessageRetried(WorkerMessageRetriedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $this->popDuration($envelope);

        $this->endpoint->send(new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_RETRIED,
            command: self::messageClass($envelope),
            transport: $event->getReceiverName(),
        ));
    }

    private function rememberStart(Envelope $envelope): void
    {
        if (count($this->startedAt) >= self::DURATION_MAP_MAX) {
            $oldest = array_key_first($this->startedAt);

            if ($oldest !== null) {
                unset($this->startedAt[$oldest]);
            }
        }

        $this->startedAt[spl_object_id($envelope->getMessage())] = $this->now();
    }

    private function popDuration(Envelope $envelope): ?float
    {
        $id = spl_object_id($envelope->getMessage());

        if (!isset($this->startedAt[$id])) {
            return null;
        }

        $start = $this->startedAt[$id];
        unset($this->startedAt[$id]);

        return $this->now() - $start;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function ensureNonBlocking(): void
    {
        if (!$this->stdinReady) {
            stream_set_blocking($this->stdinStream, false);
            $this->stdinReady = true;
        }
    }

    private function drainStdin(): void
    {
        while (true) {
            $chunk = fread($this->stdinStream, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->stdinBuffer .= $chunk;
        }

        while (($newlinePosition = strpos($this->stdinBuffer, "\n")) !== false) {
            $line = substr($this->stdinBuffer, 0, $newlinePosition);
            $this->stdinBuffer = substr($this->stdinBuffer, $newlinePosition + 1);
            $line = rtrim($line, "\r");

            $message = $this->codec->decode($line);

            if ($message === null) {
                continue;
            }

            $this->handleIncomingMessage($message);
        }
    }

    private function handleIncomingMessage(\SymfonyProcessManager\Ipc\IpcMessage $message): void
    {
        if ($message instanceof PingMessage) {
            $this->endpoint->send(new PongMessage());
        }
    }

    private static function messageClass(Envelope $envelope): string
    {
        return $envelope->getMessage()::class;
    }
}
