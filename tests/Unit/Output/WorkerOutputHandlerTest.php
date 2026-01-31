<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Output\WorkerOutputHandler;

#[CoversClass(WorkerOutputHandler::class)]
final class WorkerOutputHandlerTest extends TestCase
{
    /** @var resource */
    private $stdoutStream;

    /** @var resource */
    private $stderrStream;

    private WorkerOutputHandler $handler;

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'r+');
        self::assertIsResource($stdout);
        $this->stdoutStream = $stdout;

        $stderr = fopen('php://memory', 'r+');
        self::assertIsResource($stderr);
        $this->stderrStream = $stderr;

        $this->handler = new WorkerOutputHandler(
            new WorkerOutputFormatter(),
            $this->stdoutStream,
            $this->stderrStream,
        );
    }

    public function testHandleOutputWritesCompleteLineToStdoutStream(): void
    {
        $this->handler->handleOutput(1, Process::OUT, "hello world\n");

        self::assertSame("[worker 1] hello world" . PHP_EOL, $this->readStream($this->stdoutStream));
        self::assertSame('', $this->readStream($this->stderrStream));
    }

    public function testHandleOutputWritesErrTypeToStderrStream(): void
    {
        $this->handler->handleOutput(1, Process::ERR, "error message\n");

        self::assertSame('', $this->readStream($this->stdoutStream));
        self::assertSame("[worker 1] error message" . PHP_EOL, $this->readStream($this->stderrStream));
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

        self::assertSame("[worker 1] partial data" . PHP_EOL, $this->readStream($this->stdoutStream));
    }

    public function testMultipleLinesInSingleBufferAreForwardedIndividually(): void
    {
        $this->handler->handleOutput(1, Process::OUT, "line one\nline two\n");

        $expected = "[worker 1] line one" . PHP_EOL . "[worker 1] line two" . PHP_EOL;
        self::assertSame($expected, $this->readStream($this->stdoutStream));
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
