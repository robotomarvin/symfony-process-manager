<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\Output;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Metrics\MetricFactory;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Metrics\MetricsRegistry;
use SymfonyProcessManager\Metrics\PrometheusTextRenderer;

#[CoversClass(WorkerOutputFormatter::class)]
final class WorkerOutputFormatterTest extends TestCase
{
    private WorkerOutputFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new WorkerOutputFormatter();
    }

    private function createFormatterWithMetrics(MetricsRegistry $metrics): WorkerOutputFormatter
    {
        return new WorkerOutputFormatter($metrics);
    }

    public function testJsonWithNoExtraKeyAddsWorkerIdInExtra(): void
    {
        $input = json_encode(['message' => 'hello'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(1, $input);
        $decoded = $this->decodeJson($result);

        self::assertSame('hello', $decoded['message']);
        self::assertIsArray($decoded['extra']);
        self::assertSame(1, $decoded['extra']['worker_id']);
    }

    public function testJsonWithExistingExtraArrayMergesWorkerId(): void
    {
        $input = json_encode(['message' => 'hi', 'extra' => ['channel' => 'app']], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(2, $input);
        $decoded = $this->decodeJson($result);

        self::assertIsArray($decoded['extra']);
        self::assertSame('app', $decoded['extra']['channel']);
        self::assertSame(2, $decoded['extra']['worker_id']);
    }

    public function testJsonWithExtraNullReplacesWithWorkerId(): void
    {
        $input = json_encode(['message' => 'test', 'extra' => null], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(1, $input);
        $decoded = $this->decodeJson($result);

        self::assertSame(['worker_id' => 1], $decoded['extra']);
    }

    public function testJsonWithExtraNonArrayValueReplacesWithWorkerId(): void
    {
        $input = json_encode(['message' => 'test', 'extra' => 'string-value'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(3, $input);
        $decoded = $this->decodeJson($result);

        self::assertSame(['worker_id' => 3], $decoded['extra']);
    }

    public function testEmptyJsonObjectReturnPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, '{}');

        self::assertSame('[worker 1] {}', $result);
    }

    public function testNonAssociativeJsonArrayReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, '[1,2,3]');

        self::assertSame('[worker 1] [1,2,3]', $result);
    }

    public function testMalformedJsonReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, '{bad json}');

        self::assertSame('[worker 1] {bad json}', $result);
    }

    public function testEmptyStringReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, '');

        self::assertSame('[worker 1] ', $result);
    }

    public function testPlainTextReturnsPlainTextPrefix(): void
    {
        $result = $this->formatter->format(1, 'some plain text');

        self::assertSame('[worker 1] some plain text', $result);
    }

    public function testJsonEncodingPreservesSlashesAndUnicode(): void
    {
        $input = json_encode(['path' => '/var/log', 'name' => "caf\u{00E9}"], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(1, $input);

        self::assertStringContainsString('/var/log', $result);
        self::assertStringContainsString("caf\u{00E9}", $result);
        self::assertStringNotContainsString('\/', $result);
    }

    public function testWorkerIdZeroInJsonPath(): void
    {
        $input = json_encode(['message' => 'test'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(0, $input);
        $decoded = $this->decodeJson($result);

        self::assertIsArray($decoded['extra']);
        self::assertSame(0, $decoded['extra']['worker_id']);
    }

    public function testWorkerIdZeroInPlainTextPath(): void
    {
        $result = $this->formatter->format(0, 'hello');

        self::assertSame('[worker 0] hello', $result);
    }

    public function testLargeWorkerIdInJsonPath(): void
    {
        $input = json_encode(['message' => 'test'], JSON_THROW_ON_ERROR);
        $result = $this->formatter->format(99, $input);
        $decoded = $this->decodeJson($result);

        self::assertIsArray($decoded['extra']);
        self::assertSame(99, $decoded['extra']['worker_id']);
    }

    public function testLargeWorkerIdInPlainTextPath(): void
    {
        $result = $this->formatter->format(99, 'hello');

        self::assertSame('[worker 99] hello', $result);
    }

    public function testJsonWithHandledSuccessfullyIncrementsCounter(): void
    {
        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $formatter = $this->createFormatterWithMetrics($metrics);
        $input = json_encode([
            'message' => 'Received message App\Message\TestMessage was handled successfully (acknowledging to transport).',
            'context' => [],
        ], JSON_THROW_ON_ERROR);

        $formatter->format(1, $input, 'async');

        $output = $metrics->toPrometheusText();
        self::assertStringContainsString('messages_processed_total{transport="async"} 1', $output);
    }

    public function testJsonWithoutHandledSuccessfullyDoesNotIncrementCounter(): void
    {
        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $formatter = $this->createFormatterWithMetrics($metrics);
        $input = json_encode([
            'message' => 'Some other log message.',
            'context' => [],
        ], JSON_THROW_ON_ERROR);

        $formatter->format(1, $input, 'async');

        $output = $metrics->toPrometheusText();
        self::assertStringNotContainsString('messages_processed_total', $output);
    }

    public function testPlainTextDoesNotIncrementCounter(): void
    {
        $metrics = new MetricsRegistry(new PrometheusTextRenderer(), new MetricFactory());
        $formatter = $this->createFormatterWithMetrics($metrics);
        $formatter->format(1, 'was handled successfully (acknowledging to transport).', 'async');

        $output = $metrics->toPrometheusText();
        self::assertStringNotContainsString('messages_processed_total', $output);
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
