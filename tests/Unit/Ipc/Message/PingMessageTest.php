<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\Message\PingMessage;

#[CoversClass(PingMessage::class)]
final class PingMessageTest extends TestCase
{
    public function testToArrayReturnsEmptyArray(): void
    {
        $message = new PingMessage();

        self::assertSame([], $message->toArray());
    }

    public function testFromArrayCreatesInstance(): void
    {
        $message = PingMessage::fromArray([]);

        self::assertInstanceOf(PingMessage::class, $message);
    }
}
