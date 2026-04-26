<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\Message\MessengerEventMessage;

#[CoversClass(MessengerEventMessage::class)]
final class MessengerEventMessageTest extends TestCase
{
    public function testToArrayIncludesRequiredFields(): void
    {
        $message = new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_RECEIVED,
            command: 'App\\Message\\Foo',
            transport: 'async',
        );

        self::assertSame([
            'event' => 'received',
            'command' => 'App\\Message\\Foo',
            'transport' => 'async',
        ], $message->toArray());
    }

    public function testToArrayIncludesOptionalFieldsWhenPresent(): void
    {
        $message = new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_FAILED,
            command: 'App\\Message\\Foo',
            transport: 'async',
            durationSeconds: 1.234,
            errorClass: 'RuntimeException',
        );

        $data = $message->toArray();

        self::assertSame(1.234, $data['duration_seconds']);
        self::assertSame('RuntimeException', $data['error_class']);
    }

    public function testFromArrayRoundTrip(): void
    {
        $original = new MessengerEventMessage(
            event: MessengerEventMessage::EVENT_HANDLED,
            command: 'App\\Message\\Foo',
            transport: 'async',
            durationSeconds: 0.5,
        );

        $restored = MessengerEventMessage::fromArray($original->toArray());

        self::assertSame('handled', $restored->event);
        self::assertSame('App\\Message\\Foo', $restored->command);
        self::assertSame('async', $restored->transport);
        self::assertSame(0.5, $restored->durationSeconds);
        self::assertNull($restored->errorClass);
    }

    public function testFromArrayHandlesMissingFields(): void
    {
        $message = MessengerEventMessage::fromArray([]);

        self::assertSame('', $message->event);
        self::assertSame('', $message->command);
        self::assertSame('', $message->transport);
        self::assertNull($message->durationSeconds);
        self::assertNull($message->errorClass);
    }
}
