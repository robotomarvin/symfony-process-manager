<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
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
use SymfonyProcessManager\Worker\WorkerIpcSubscriber;

#[CoversClass(WorkerIpcSubscriber::class)]
final class WorkerIpcSubscriberTest extends TestCase
{
    private IpcCodec $codec;
    private FixedClock $clock;

    /** @var resource */
    private $stdoutStream;

    /** @var resource */
    private $stdinStream;

    protected function setUp(): void
    {
        $this->codec = new IpcCodec();
        $this->clock = new FixedClock(1_000_000.0);

        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $this->stdoutStream = $stdout;

        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        $this->stdinStream = $stdin;
    }

    public function testOnMessageReceivedSendsReceivedEvent(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());

        $subscriber->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $message = $this->readSingleMessage();

        self::assertInstanceOf(MessengerEventMessage::class, $message);
        self::assertSame(MessengerEventMessage::EVENT_RECEIVED, $message->event);
        self::assertSame('stdClass', $message->command);
        self::assertSame('async', $message->transport);
        self::assertNull($message->durationSeconds);
        self::assertNull($message->errorClass);
    }

    public function testOnMessageHandledIncludesDurationFromReceivedTimestamp(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());

        $subscriber->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->clock->advance(0.5);
        $subscriber->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $messages = $this->readAllMessages();
        self::assertCount(2, $messages);

        $handled = $messages[1];
        self::assertInstanceOf(MessengerEventMessage::class, $handled);
        self::assertSame(MessengerEventMessage::EVENT_HANDLED, $handled->event);
        self::assertNotNull($handled->durationSeconds);
        self::assertEqualsWithDelta(0.5, $handled->durationSeconds, 0.001);
    }

    public function testOnMessageHandledWithoutPriorReceivedHasNullDuration(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());

        $subscriber->onMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $message = $this->readSingleMessage();
        self::assertInstanceOf(MessengerEventMessage::class, $message);
        self::assertNull($message->durationSeconds);
    }

    public function testOnMessageFailedIncludesDurationAndErrorClass(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());

        $subscriber->onMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->clock->advance(0.25);
        $subscriber->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('boom')));

        $messages = $this->readAllMessages();
        self::assertCount(2, $messages);

        $failed = $messages[1];
        self::assertInstanceOf(MessengerEventMessage::class, $failed);
        self::assertSame(MessengerEventMessage::EVENT_FAILED, $failed->event);
        self::assertSame(\RuntimeException::class, $failed->errorClass);
        self::assertNotNull($failed->durationSeconds);
        self::assertEqualsWithDelta(0.25, $failed->durationSeconds, 0.001);
    }

    public function testOnMessageRetriedSendsRetriedEvent(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());

        $subscriber->onMessageRetried(new WorkerMessageRetriedEvent($envelope, 'async'));

        $message = $this->readSingleMessage();
        self::assertInstanceOf(MessengerEventMessage::class, $message);
        self::assertSame(MessengerEventMessage::EVENT_RETRIED, $message->event);
        self::assertSame('async', $message->transport);
    }

    public function testOnWorkerRunningDrainsStdinAndHandlesPing(): void
    {
        $pingLine = $this->codec->encode(new PingMessage()) . "\n";
        fwrite($this->stdinStream, $pingLine);
        rewind($this->stdinStream);

        $subscriber = $this->createSubscriber();
        $subscriber->onWorkerRunning();

        $message = $this->readSingleMessage();
        self::assertInstanceOf(PongMessage::class, $message);
    }

    public function testOnWorkerRunningIgnoresInvalidIpcLines(): void
    {
        fwrite($this->stdinStream, "@spm:{bad json\n");
        rewind($this->stdinStream);

        $subscriber = $this->createSubscriber();
        $subscriber->onWorkerRunning();

        self::assertSame('', $this->readStream($this->stdoutStream));
    }

    public function testOnWorkerRunningIgnoresNonIpcLines(): void
    {
        fwrite($this->stdinStream, "regular text\n");
        rewind($this->stdinStream);

        $subscriber = $this->createSubscriber();
        $subscriber->onWorkerRunning();

        self::assertSame('', $this->readStream($this->stdoutStream));
    }

    public function testGetSubscribedEventsCoversAllMessengerEvents(): void
    {
        $events = WorkerIpcSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(WorkerRunningEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageReceivedEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageHandledEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageFailedEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageRetriedEvent::class, $events);
    }

    private function createSubscriber(): WorkerIpcSubscriber
    {
        return new WorkerIpcSubscriber(
            new IpcEndpoint($this->codec, $this->stdoutStream),
            $this->codec,
            $this->clock,
            $this->stdinStream,
        );
    }

    private function readSingleMessage(): \SymfonyProcessManager\Ipc\IpcMessage
    {
        $messages = $this->readAllMessages();
        self::assertCount(1, $messages);

        return $messages[0];
    }

    /**
     * @return list<\SymfonyProcessManager\Ipc\IpcMessage>
     */
    private function readAllMessages(): array
    {
        $output = $this->readStream($this->stdoutStream);
        $lines = array_values(array_filter(explode("\n", $output), static fn(string $l): bool => $l !== ''));

        return array_map(function (string $line): \SymfonyProcessManager\Ipc\IpcMessage {
            $message = $this->codec->decode($line);
            self::assertNotNull($message);

            return $message;
        }, $lines);
    }

    /**
     * @param resource $stream
     */
    private function readStream(mixed $stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}

final class FixedClock implements ClockInterface
{
    private float $current;

    public function __construct(float $start)
    {
        $this->current = $start;
    }

    public function advance(float $seconds): void
    {
        $this->current += $seconds;
    }

    public function now(): \DateTimeImmutable
    {
        $result = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', $this->current));
        assert($result instanceof \DateTimeImmutable);

        return $result;
    }

    public function sleep(float|int $seconds): void
    {
        $this->current += $seconds;
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this;
    }
}
