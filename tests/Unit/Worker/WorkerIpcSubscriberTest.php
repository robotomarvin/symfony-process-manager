<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcEndpoint;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;
use SymfonyProcessManager\Ipc\Message\WorkerStartedHandlingMessage;
use SymfonyProcessManager\Worker\WorkerIpcSubscriber;

#[CoversClass(WorkerIpcSubscriber::class)]
final class WorkerIpcSubscriberTest extends TestCase
{
    private IpcCodec $codec;

    /** @var resource */
    private $stdoutStream;

    /** @var resource */
    private $stdinStream;

    protected function setUp(): void
    {
        $this->codec = new IpcCodec();

        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $this->stdoutStream = $stdout;

        $stdin = fopen('php://memory', 'r+');
        self::assertIsResource($stdin);
        $this->stdinStream = $stdin;
    }

    public function testOnMessageHandledSendsProcessedCommandMessage(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageHandledEvent($envelope, 'async');

        $subscriber->onMessageHandled($event);

        $output = $this->readStream($this->stdoutStream);
        $message = $this->codec->decode(trim($output));

        self::assertInstanceOf(ProcessedCommandMessage::class, $message);
        self::assertSame('handled', $message->status);
        self::assertSame('stdClass', $message->command);
        self::assertNull($message->errorInfo);
    }

    public function testOnMessageFailedSendsProcessedCommandMessageWithError(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException('Something broke'));

        $subscriber->onMessageFailed($event);

        $output = $this->readStream($this->stdoutStream);
        $message = $this->codec->decode(trim($output));

        self::assertInstanceOf(ProcessedCommandMessage::class, $message);
        self::assertSame('failed', $message->status);
        self::assertSame('stdClass', $message->command);
        self::assertSame('Something broke', $message->errorInfo);
    }

    public function testOnWorkerRunningDrainsStdinAndHandlesPing(): void
    {
        $pingLine = $this->codec->encode(new PingMessage()) . "\n";
        fwrite($this->stdinStream, $pingLine);
        rewind($this->stdinStream);

        $subscriber = $this->createSubscriber();
        $subscriber->onWorkerRunning();

        $output = $this->readStream($this->stdoutStream);
        $message = $this->codec->decode(trim($output));

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

    public function testOnWorkerRunningHandlesMultipleMessages(): void
    {
        $ping1 = $this->codec->encode(new PingMessage()) . "\n";
        $ping2 = $this->codec->encode(new PingMessage()) . "\n";
        fwrite($this->stdinStream, $ping1 . $ping2);
        rewind($this->stdinStream);

        $subscriber = $this->createSubscriber();
        $subscriber->onWorkerRunning();

        $output = $this->readStream($this->stdoutStream);
        $lines = array_filter(explode("\n", $output));

        self::assertCount(2, $lines);

        foreach ($lines as $line) {
            self::assertInstanceOf(PongMessage::class, $this->codec->decode($line));
        }
    }

    public function testGetSubscribedEventsReturnsCorrectMapping(): void
    {
        $events = WorkerIpcSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(WorkerRunningEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageReceivedEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageHandledEvent::class, $events);
        self::assertArrayHasKey(WorkerMessageFailedEvent::class, $events);
    }

    public function testOnMessageReceivedSendsWorkerStartedHandlingMessage(): void
    {
        $subscriber = $this->createSubscriber();
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageReceivedEvent($envelope, 'async');

        $subscriber->onMessageReceived($event);

        $output = $this->readStream($this->stdoutStream);
        $message = $this->codec->decode(trim($output));

        self::assertInstanceOf(WorkerStartedHandlingMessage::class, $message);
        self::assertSame('stdClass', $message->command);
    }

    public function testErrorInfoIsTruncatedTo500Chars(): void
    {
        $subscriber = $this->createSubscriber();
        $longMessage = str_repeat('x', 1000);
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, 'async', new \RuntimeException($longMessage));

        $subscriber->onMessageFailed($event);

        $output = $this->readStream($this->stdoutStream);
        $message = $this->codec->decode(trim($output));

        self::assertInstanceOf(ProcessedCommandMessage::class, $message);
        self::assertSame(500, mb_strlen($message->errorInfo ?? ''));
    }

    private function createSubscriber(): WorkerIpcSubscriber
    {
        return new WorkerIpcSubscriber(
            new IpcEndpoint($this->codec, $this->stdoutStream),
            $this->codec,
            $this->stdinStream,
        );
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
