<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Process\InputStream;
use SymfonyProcessManager\Ipc\Filter\WorkerIdFilter;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcFanout;
use SymfonyProcessManager\Ipc\Message\PingMessage;

#[CoversClass(IpcFanout::class)]
final class IpcFanoutTest extends TestCase
{
    private IpcCodec $codec;
    private IpcFanout $fanout;

    protected function setUp(): void
    {
        $this->codec = new IpcCodec();
        $this->fanout = new IpcFanout($this->codec, new NullLogger());
    }

    public function testSendBroadcastsToAllRegisteredWorkers(): void
    {
        $stream1 = $this->createMock(InputStream::class);
        $stream2 = $this->createMock(InputStream::class);

        $expected = $this->codec->encode(new PingMessage()) . "\n";

        $stream1->method('isClosed')->willReturn(false);
        $stream1->expects(self::once())->method('write')->with($expected);

        $stream2->method('isClosed')->willReturn(false);
        $stream2->expects(self::once())->method('write')->with($expected);

        $this->fanout->register(1, $stream1);
        $this->fanout->register(2, $stream2);

        $this->fanout->send(new PingMessage());
    }

    public function testSendWithFilterTargetsSingleWorker(): void
    {
        $stream1 = $this->createMock(InputStream::class);
        $stream2 = $this->createMock(InputStream::class);

        $stream1->method('isClosed')->willReturn(false);
        $stream1->expects(self::never())->method('write');

        $stream2->method('isClosed')->willReturn(false);
        $stream2->expects(self::once())->method('write');

        $this->fanout->register(1, $stream1);
        $this->fanout->register(2, $stream2);

        $this->fanout->send(new PingMessage(), [new WorkerIdFilter(2)]);
    }

    public function testSendSkipsClosedStreams(): void
    {
        $stream = $this->createMock(InputStream::class);
        $stream->method('isClosed')->willReturn(true);
        $stream->expects(self::never())->method('write');

        $this->fanout->register(1, $stream);

        $this->fanout->send(new PingMessage());
    }

    public function testUnregisterRemovesWorker(): void
    {
        $stream = $this->createMock(InputStream::class);
        $stream->expects(self::never())->method('write');

        $this->fanout->register(1, $stream);
        $this->fanout->unregister(1);

        $this->fanout->send(new PingMessage());
    }

    public function testMultipleFiltersUseAndSemantics(): void
    {
        $stream1 = $this->createMock(InputStream::class);
        $stream2 = $this->createMock(InputStream::class);

        $stream1->expects(self::never())->method('write');
        $stream2->expects(self::never())->method('write');

        $this->fanout->register(1, $stream1);
        $this->fanout->register(2, $stream2);

        // Both filters must match — worker 1 matches first but not second
        $this->fanout->send(new PingMessage(), [new WorkerIdFilter(1), new WorkerIdFilter(2)]);
    }

    public function testSendDoesNotThrowOnWriteFailure(): void
    {
        $stream = $this->createMock(InputStream::class);
        $stream->method('isClosed')->willReturn(false);
        $stream->method('write')->willThrowException(new \RuntimeException('broken pipe'));

        $this->fanout->register(1, $stream);
        $this->fanout->send(new PingMessage());

        // Verifies no exception propagated — failure was caught and logged
        $this->addToAssertionCount(1);
    }
}
