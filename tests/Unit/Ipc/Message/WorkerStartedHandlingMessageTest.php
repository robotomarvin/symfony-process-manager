<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\Message\WorkerStartedHandlingMessage;

#[CoversClass(WorkerStartedHandlingMessage::class)]
final class WorkerStartedHandlingMessageTest extends TestCase
{
    public function testToArray(): void
    {
        $message = new WorkerStartedHandlingMessage('App\Foo');

        self::assertSame(['command' => 'App\Foo'], $message->toArray());
    }

    public function testFromArrayHandlesMissingFields(): void
    {
        $message = WorkerStartedHandlingMessage::fromArray([]);

        self::assertSame('', $message->command);
    }

    public function testRoundTripsThroughCodec(): void
    {
        $codec = new IpcCodec([WorkerStartedHandlingMessage::class]);
        $original = new WorkerStartedHandlingMessage('App\Bar');

        $encoded = $codec->encode($original);
        $decoded = $codec->decode($encoded);

        self::assertInstanceOf(WorkerStartedHandlingMessage::class, $decoded);
        self::assertSame('App\Bar', $decoded->command);
    }
}
