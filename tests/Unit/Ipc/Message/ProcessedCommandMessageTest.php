<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;

#[CoversClass(ProcessedCommandMessage::class)]
final class ProcessedCommandMessageTest extends TestCase
{
    public function testToArrayWithoutError(): void
    {
        $message = new ProcessedCommandMessage('handled', 'App\\Message\\Test');

        self::assertSame([
            'status' => 'handled',
            'command' => 'App\\Message\\Test',
        ], $message->toArray());
    }

    public function testToArrayWithError(): void
    {
        $message = new ProcessedCommandMessage('failed', 'App\\Message\\Test', 'Error details');

        self::assertSame([
            'status' => 'failed',
            'command' => 'App\\Message\\Test',
            'error_info' => 'Error details',
        ], $message->toArray());
    }

    public function testFromArrayWithoutError(): void
    {
        $message = ProcessedCommandMessage::fromArray([
            'status' => 'handled',
            'command' => 'App\\Message\\Test',
        ]);

        self::assertSame('handled', $message->status);
        self::assertSame('App\\Message\\Test', $message->command);
        self::assertNull($message->errorInfo);
    }

    public function testFromArrayWithError(): void
    {
        $message = ProcessedCommandMessage::fromArray([
            'status' => 'failed',
            'command' => 'App\\Message\\Test',
            'error_info' => 'Something broke',
        ]);

        self::assertSame('failed', $message->status);
        self::assertSame('App\\Message\\Test', $message->command);
        self::assertSame('Something broke', $message->errorInfo);
    }

    public function testFromArrayWithMissingFieldsDefaultsToEmptyStrings(): void
    {
        $message = ProcessedCommandMessage::fromArray([]);

        self::assertSame('', $message->status);
        self::assertSame('', $message->command);
        self::assertNull($message->errorInfo);
    }
}
