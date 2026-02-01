<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Worker;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcEndpoint;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;

final class WorkerIpcSubscriber implements EventSubscriberInterface
{
    private string $stdinBuffer = '';

    /** @var resource */
    private readonly mixed $stdinStream;

    private bool $stdinReady = false;

    /**
     * @param resource|null $stdinStream
     */
    public function __construct(
        private readonly IpcEndpoint $endpoint,
        private readonly IpcCodec $codec,
        mixed $stdinStream = null,
    ) {
        $this->stdinStream = $stdinStream ?? \STDIN;

        assert(\is_resource($this->stdinStream), 'stdinStream must be a resource');
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onWorkerRunning(): void
    {
        $this->ensureNonBlocking();
        $this->drainStdin();
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $messageClass = $this->getMessageClass($event->getEnvelope()->getMessage());

        $this->endpoint->send(new ProcessedCommandMessage(
            status: 'handled',
            command: $messageClass,
        ));
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $messageClass = $this->getMessageClass($event->getEnvelope()->getMessage());
        $errorInfo = mb_substr($event->getThrowable()->getMessage(), 0, 500);

        $this->endpoint->send(new ProcessedCommandMessage(
            status: 'failed',
            command: $messageClass,
            errorInfo: $errorInfo,
        ));
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

    private function getMessageClass(object $message): string
    {
        return $message::class;
    }
}
