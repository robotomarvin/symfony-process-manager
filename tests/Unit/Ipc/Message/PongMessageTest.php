<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\Message\PongMessage;

#[CoversClass(PongMessage::class)]
final class PongMessageTest extends TestCase
{
    public function testToArrayReturnsEmptyArray(): void
    {
        $message = new PongMessage();

        self::assertSame([], $message->toArray());
    }

    public function testFromArrayCreatesInstance(): void
    {
        $message = PongMessage::fromArray([]);

        self::assertInstanceOf(PongMessage::class, $message);
    }
}
