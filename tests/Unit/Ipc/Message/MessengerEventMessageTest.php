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

    public function testFromArrayRejectsMissingEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MessengerEventMessage::fromArray([
            'command' => 'App\\Message\\Foo',
            'transport' => 'async',
        ]);
    }

    public function testFromArrayRejectsUnknownEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MessengerEventMessage::fromArray([
            'event' => 'bogus',
            'command' => 'App\\Message\\Foo',
            'transport' => 'async',
        ]);
    }

    public function testFromArrayRejectsMissingCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MessengerEventMessage::fromArray([
            'event' => MessengerEventMessage::EVENT_RECEIVED,
            'transport' => 'async',
        ]);
    }

    public function testFromArrayRejectsEmptyTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MessengerEventMessage::fromArray([
            'event' => MessengerEventMessage::EVENT_HANDLED,
            'command' => 'App\\Message\\Foo',
            'transport' => '',
        ]);
    }

    public function testFromArrayRejectsMissingTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MessengerEventMessage::fromArray([
            'event' => MessengerEventMessage::EVENT_HANDLED,
            'command' => 'App\\Message\\Foo',
        ]);
    }
}
