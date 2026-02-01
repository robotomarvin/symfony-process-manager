<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\Message\PingMessage;
use SymfonyProcessManager\Ipc\Message\PongMessage;
use SymfonyProcessManager\Ipc\Message\ProcessedCommandMessage;

#[CoversClass(IpcCodec::class)]
final class IpcCodecTest extends TestCase
{
    private IpcCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new IpcCodec();
    }

    public function testEncodeProducesCorrectFormat(): void
    {
        $message = new PingMessage();
        $encoded = $this->codec->encode($message);

        self::assertStringStartsWith('@spm:', $encoded);

        $json = substr($encoded, 5);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(PingMessage::class, $decoded['type']);
        self::assertSame([], $decoded['payload']);
    }

    public function testRoundTripPingMessage(): void
    {
        $original = new PingMessage();
        $encoded = $this->codec->encode($original);
        $decoded = $this->codec->decode($encoded);

        self::assertInstanceOf(PingMessage::class, $decoded);
    }

    public function testRoundTripPongMessage(): void
    {
        $original = new PongMessage();
        $encoded = $this->codec->encode($original);
        $decoded = $this->codec->decode($encoded);

        self::assertInstanceOf(PongMessage::class, $decoded);
    }

    public function testRoundTripProcessedCommandMessage(): void
    {
        $original = new ProcessedCommandMessage('handled', 'App\\Message\\Test', null);
        $encoded = $this->codec->encode($original);
        $decoded = $this->codec->decode($encoded);

        self::assertInstanceOf(ProcessedCommandMessage::class, $decoded);
        self::assertSame('handled', $decoded->status);
        self::assertSame('App\\Message\\Test', $decoded->command);
        self::assertNull($decoded->errorInfo);
    }

    public function testRoundTripProcessedCommandMessageWithError(): void
    {
        $original = new ProcessedCommandMessage('failed', 'App\\Message\\Test', 'Something broke');
        $encoded = $this->codec->encode($original);
        $decoded = $this->codec->decode($encoded);

        self::assertInstanceOf(ProcessedCommandMessage::class, $decoded);
        self::assertSame('failed', $decoded->status);
        self::assertSame('Something broke', $decoded->errorInfo);
    }

    public function testDecodeReturnsNullForNonIpcLine(): void
    {
        self::assertNull($this->codec->decode('some regular log line'));
    }

    public function testDecodeReturnsNullForInvalidJsonAfterPrefix(): void
    {
        self::assertNull($this->codec->decode('@spm:{bad json'));
    }

    public function testDecodeReturnsNullForMissingType(): void
    {
        self::assertNull($this->codec->decode('@spm:{"payload":{}}'));
    }

    public function testDecodeReturnsNullForMissingPayload(): void
    {
        self::assertNull($this->codec->decode('@spm:{"type":"SomeClass"}'));
    }

    public function testDecodeReturnsNullForNonExistentClass(): void
    {
        self::assertNull($this->codec->decode('@spm:{"type":"NonExistent\\\\Class","payload":{}}'));
    }

    public function testDecodeReturnsNullForNonIpcMessageClass(): void
    {
        self::assertNull($this->codec->decode('@spm:{"type":"stdClass","payload":{}}'));
    }

    public function testDecodeReturnsNullForNonStringType(): void
    {
        self::assertNull($this->codec->decode('@spm:{"type":123,"payload":{}}'));
    }

    public function testDecodeReturnsNullForNonArrayPayload(): void
    {
        $type = PingMessage::class;
        self::assertNull($this->codec->decode("@spm:{\"type\":\"{$type}\",\"payload\":\"string\"}"));
    }

    public function testIsIpcLineReturnsTrueForIpcPrefix(): void
    {
        self::assertTrue(IpcCodec::isIpcLine('@spm:{}'));
    }

    public function testIsIpcLineReturnsFalseForRegularLine(): void
    {
        self::assertFalse(IpcCodec::isIpcLine('regular log line'));
    }

    public function testIsIpcLineReturnsFalseForEmptyString(): void
    {
        self::assertFalse(IpcCodec::isIpcLine(''));
    }

    public function testAllowedTypesFiltersMessages(): void
    {
        $codec = new IpcCodec([PingMessage::class]);

        $pingEncoded = $this->codec->encode(new PingMessage());
        $pongEncoded = $this->codec->encode(new PongMessage());

        self::assertInstanceOf(PingMessage::class, $codec->decode($pingEncoded));
        self::assertNull($codec->decode($pongEncoded));
    }

    public function testDecodeReturnsNullForNonArrayJson(): void
    {
        self::assertNull($this->codec->decode('@spm:"just a string"'));
    }
}
