<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Output\WorkerOutputFormatter;

#[CoversClass(WorkerOutputFormatter::class)]
final class WorkerOutputFormatterTest extends TestCase
{
    private WorkerOutputFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new WorkerOutputFormatter();
    }

    public function testJsonWithNoExtraKeyAddsWorkerIdAndConsumerInExtra(): void
    {
        $input = json_encode(['message' => 'hello'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(1, 'ingest', $input);
        $decoded = $this->decodeJson($result);

        self::assertSame('hello', $decoded['message']);
        self::assertIsArray($decoded['extra']);
        self::assertSame(1, $decoded['extra']['worker_id']);
        self::assertSame('ingest', $decoded['extra']['consumer']);
    }

    public function testJsonWithExistingExtraArrayMergesWorkerIdAndConsumer(): void
    {
        $input = json_encode(['message' => 'hi', 'extra' => ['channel' => 'app']], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(2, 'ingest', $input);
        $decoded = $this->decodeJson($result);

        self::assertIsArray($decoded['extra']);
        self::assertSame('app', $decoded['extra']['channel']);
        self::assertSame(2, $decoded['extra']['worker_id']);
        self::assertSame('ingest', $decoded['extra']['consumer']);
    }

    public function testJsonWithExtraNullReplacesWithIdentity(): void
    {
        $input = json_encode(['message' => 'test', 'extra' => null], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(1, 'async', $input);
        $decoded = $this->decodeJson($result);

        self::assertSame(['worker_id' => 1, 'consumer' => 'async'], $decoded['extra']);
    }

    public function testJsonWithExtraNonArrayValueReplacesWithIdentity(): void
    {
        $input = json_encode(['message' => 'test', 'extra' => 'string-value'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(3, 'async', $input);
        $decoded = $this->decodeJson($result);

        self::assertSame(['worker_id' => 3, 'consumer' => 'async'], $decoded['extra']);
    }

    public function testEmptyJsonObjectReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, 'async', '{}');

        self::assertSame('[worker 1 async] {}', $result);
    }

    public function testNonAssociativeJsonArrayReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, 'async', '[1,2,3]');

        self::assertSame('[worker 1 async] [1,2,3]', $result);
    }

    public function testMalformedJsonReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, 'async', '{bad json}');

        self::assertSame('[worker 1 async] {bad json}', $result);
    }

    public function testEmptyStringReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, 'async', '');

        self::assertSame('[worker 1 async] ', $result);
    }

    public function testPlainTextReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, 'ingest', 'some plain text');

        self::assertSame('[worker 1 ingest] some plain text', $result);
    }

    public function testJsonEncodingPreservesSlashesAndUnicode(): void
    {
        $input = json_encode(['path' => '/var/log', 'name' => "caf\u{00E9}"], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(1, 'async', $input);

        self::assertStringContainsString('/var/log', $result);
        self::assertStringContainsString("caf\u{00E9}", $result);
        self::assertStringNotContainsString('\/', $result);
    }

    public function testWorkerIdZeroInJsonPath(): void
    {
        $input = json_encode(['message' => 'test'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(0, 'async', $input);
        $decoded = $this->decodeJson($result);

        self::assertIsArray($decoded['extra']);
        self::assertSame(0, $decoded['extra']['worker_id']);
        self::assertSame('async', $decoded['extra']['consumer']);
    }

    public function testWorkerIdZeroInPlainTextPath(): void
    {
        $result = $this->formatter->format(0, 'async', 'hello');

        self::assertSame('[worker 0 async] hello', $result);
    }

    public function testLargeWorkerIdInJsonPath(): void
    {
        $input = json_encode(['message' => 'test'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(99, 'priority', $input);
        $decoded = $this->decodeJson($result);

        self::assertIsArray($decoded['extra']);
        self::assertSame(99, $decoded['extra']['worker_id']);
        self::assertSame('priority', $decoded['extra']['consumer']);
    }

    public function testLargeWorkerIdInPlainTextPath(): void
    {
        $result = $this->formatter->format(99, 'priority', 'hello');

        self::assertSame('[worker 99 priority] hello', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
