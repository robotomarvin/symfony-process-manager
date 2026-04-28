<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Ipc\IpcCodec;
use SymfonyProcessManager\Ipc\IpcEndpoint;
use SymfonyProcessManager\Ipc\Message\MessengerEventMessage;
use SymfonyProcessManager\Ipc\Message\PingMessage;

#[CoversClass(IpcEndpoint::class)]
final class IpcEndpointTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    public function testSendWritesEncodedMessageToStream(): void
    {
        $codec = new IpcCodec();
        $endpoint = new IpcEndpoint($codec, $this->stream);

        $endpoint->send(new PingMessage());

        $output = $this->readStream();
        self::assertStringStartsWith('@spm:', $output);
        self::assertStringEndsWith("\n", $output);

        $decoded = $codec->decode(trim($output));
        self::assertInstanceOf(PingMessage::class, $decoded);
    }

    public function testSendWritesMultipleMessages(): void
    {
        $codec = new IpcCodec();
        $endpoint = new IpcEndpoint($codec, $this->stream);

        $endpoint->send(new PingMessage());
        $endpoint->send(new MessengerEventMessage('handled', 'TestCmd', 'async'));

        $output = $this->readStream();
        $lines = array_filter(explode("\n", $output));

        self::assertCount(2, $lines);
        self::assertInstanceOf(PingMessage::class, $codec->decode($lines[0]));
        self::assertInstanceOf(MessengerEventMessage::class, $codec->decode($lines[1]));
    }

    private function readStream(): string
    {
        rewind($this->stream);
        $contents = stream_get_contents($this->stream);
        self::assertIsString($contents);

        return $contents;
    }
}
