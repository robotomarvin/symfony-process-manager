<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\MessengerEventMessage;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Output\WorkerOutputHandler;

#[CoversClass(WorkerOutputHandler::class)]
final class WorkerOutputHandlerTest extends TestCase
{
    /** @var resource */
    private $stdoutStream;

    /** @var resource */
    private $stderrStream;

    private IpcCodec $ipcCodec;
    private WorkerOutputHandler $handler;

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $this->stdoutStream = $stdout;

        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stderr);
        $this->stderrStream = $stderr;

        $this->ipcCodec = new IpcCodec();
        $this->handler = new WorkerOutputHandler(
            new WorkerOutputFormatter(),
            $this->ipcCodec,
            $this->stdoutStream,
            $this->stderrStream,
        );
        $this->handler->registerWorker(1, 'async');
        $this->handler->registerWorker(2, 'async');
    }

    public function testHandleOutputWritesCompleteLineToStdoutStream(): void
    {
        $this->handler->handleOutput(1, Process::OUT, "hello world\n");

        self::assertSame("[worker 1 async] hello world" . PHP_EOL, $this->readStream($this->stdoutStream));
        self::assertSame('', $this->readStream($this->stderrStream));
    }

    public function testHandleOutputWritesErrTypeToStderrStream(): void
    {
        $this->handler->handleOutput(1, Process::ERR, "error message\n");

        self::assertSame('', $this->readStream($this->stdoutStream));
        self::assertSame("[worker 1 async] error message" . PHP_EOL, $this->readStream($this->stderrStream));
    }

    public function testHandleOutputBuffersIncompleteLines(): void
    {
        $this->handler->handleOutput(1, Process::OUT, 'partial data');

        self::assertSame('', $this->readStream($this->stdoutStream));
    }

    public function testFlushWritesRemainingBufferToStream(): void
    {
        $this->handler->handleOutput(1, Process::OUT, 'partial data');
        $this->handler->flush(1);

        self::assertSame("[worker 1 async] partial data" . PHP_EOL, $this->readStream($this->stdoutStream));
    }

    public function testMultipleLinesInSingleBufferAreForwardedIndividually(): void
    {
        $this->handler->handleOutput(1, Process::OUT, "line one\nline two\n");

        $expected = "[worker 1 async] line one" . PHP_EOL . "[worker 1 async] line two" . PHP_EOL;
        self::assertSame($expected, $this->readStream($this->stdoutStream));
    }

    public function testIpcLineIsExtractedAndNotForwardedToStream(): void
    {
        $ipcLine = $this->ipcCodec->encode(new PingMessage());
        $this->handler->handleOutput(1, Process::OUT, $ipcLine . "\n");

        self::assertSame('', $this->readStream($this->stdoutStream));
    }

    public function testIpcLineIsQueuedAsDecodedMessage(): void
    {
        $ipcLine = $this->ipcCodec->encode(new PingMessage());
        $this->handler->handleOutput(1, Process::OUT, $ipcLine . "\n");

        $messages = $this->handler->getAndClearIpcMessages(1);
        self::assertCount(1, $messages);
        self::assertInstanceOf(PingMessage::class, $messages[0]);
    }

    public function testGetAndClearIpcMessagesClearsQueue(): void
    {
        $ipcLine = $this->ipcCodec->encode(new PingMessage());
        $this->handler->handleOutput(1, Process::OUT, $ipcLine . "\n");

        $this->handler->getAndClearIpcMessages(1);
        $second = $this->handler->getAndClearIpcMessages(1);

        self::assertSame([], $second);
    }

    public function testGetAndClearIpcMessagesReturnsEmptyForUnknownWorker(): void
    {
        self::assertSame([], $this->handler->getAndClearIpcMessages(999));
    }

    public function testMixedIpcAndNormalOutputInSingleChunk(): void
    {
        $ipcLine = $this->ipcCodec->encode(new MessengerEventMessage('handled', 'TestCmd', 'async'));
        $this->handler->handleOutput(1, Process::OUT, "normal log\n" . $ipcLine . "\nanother log\n");

        $expected = "[worker 1 async] normal log" . PHP_EOL . "[worker 1 async] another log" . PHP_EOL;
        self::assertSame($expected, $this->readStream($this->stdoutStream));

        $messages = $this->handler->getAndClearIpcMessages(1);
        self::assertCount(1, $messages);
        self::assertInstanceOf(MessengerEventMessage::class, $messages[0]);
    }

    public function testInvalidIpcJsonIsIgnoredAndNotQueued(): void
    {
        $this->handler->handleOutput(1, Process::OUT, "@spm:{bad json\n");

        self::assertSame('', $this->readStream($this->stdoutStream));
        self::assertSame([], $this->handler->getAndClearIpcMessages(1));
    }

    public function testMultipleIpcMessagesInSingleChunk(): void
    {
        $ping = $this->ipcCodec->encode(new PingMessage());
        $cmd = $this->ipcCodec->encode(new MessengerEventMessage('failed', 'Cmd', 'async', null, 'RuntimeException'));
        $this->handler->handleOutput(1, Process::OUT, $ping . "\n" . $cmd . "\n");

        $messages = $this->handler->getAndClearIpcMessages(1);
        self::assertCount(2, $messages);
        self::assertInstanceOf(PingMessage::class, $messages[0]);
        self::assertInstanceOf(MessengerEventMessage::class, $messages[1]);
    }

    public function testIpcMessagesFromDifferentWorkersAreIsolated(): void
    {
        $ipcLine = $this->ipcCodec->encode(new PingMessage());
        $this->handler->handleOutput(1, Process::OUT, $ipcLine . "\n");
        $this->handler->handleOutput(2, Process::OUT, $ipcLine . "\n" . $ipcLine . "\n");

        self::assertCount(1, $this->handler->getAndClearIpcMessages(1));
        self::assertCount(2, $this->handler->getAndClearIpcMessages(2));
    }

    public function testRegisterWorkerThreadsConsumerLabelToFormatter(): void
    {
        $this->handler->registerWorker(7, 'ingest');
        $this->handler->handleOutput(7, Process::OUT, "hello\n");

        self::assertSame('[worker 7 ingest] hello' . PHP_EOL, $this->readStream($this->stdoutStream));
    }

    public function testUnregisterWorkerStopsAttachingConsumerLabel(): void
    {
        $this->handler->registerWorker(7, 'ingest');
        $this->handler->unregisterWorker(7);
        $this->handler->handleOutput(7, Process::OUT, "hello\n");

        // Falls back to empty consumer label when not registered.
        self::assertSame('[worker 7 ] hello' . PHP_EOL, $this->readStream($this->stdoutStream));
    }

    /**
     * @param resource $stream
     */
    private function readStream($stream): string
    {
        rewind($stream);

        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
