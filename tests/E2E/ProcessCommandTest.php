<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\Output\WorkerOutputFormatter;
use SymfonyProcessManager\Output\WorkerOutputHandler;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\ShutdownState;
use SymfonyProcessManager\ProcessManager\WorkerState;
use SymfonyProcessManager\Worker\WorkerProcessFactory;
use SymfonyProcessManager\Command\ServeCommand;
use SymfonyProcessManager\Tests\Support\ConsoleProcessRunner;
use SymfonyProcessManager\Tests\Support\ConsoleProcessSession;

#[CoversClass(ServeCommand::class)]
#[CoversClass(HttpServer::class)]
#[CoversClass(ProcessManagerLoop::class)]
#[CoversClass(ShutdownState::class)]
#[CoversClass(WorkerOutputFormatter::class)]
#[CoversClass(WorkerOutputHandler::class)]
#[CoversClass(WorkerProcessFactory::class)]
#[CoversClass(WorkerState::class)]
final class ProcessCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $this->prepareDoctrineTransport();
    }

    public function testProcessCommandStartsAndStopsOnSigterm(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForWorkerStart($session, 5.0);
            $session->signal(SIGTERM);
            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Process manager shutting down.',
                5.0,
            );
            self::assertSame(0, $session->waitForExit(5.0));
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testHttpEndpointResponds(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $httpRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'HTTP server listening.',
                5.0,
            );

            $address = $httpRecord['context']['address'] ?? null;
            self::assertIsString($address);

            $address = str_replace('tcp://', '', $address);
            $url = "http://{$address}/";
            $response = @file_get_contents($url);

            self::assertIsString($response);
            $decoded = json_decode($response, true);
            self::assertIsArray($decoded);
            self::assertSame('ok', $decoded['status']);
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testMetricsEndpointResponds(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $httpRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'HTTP server listening.',
                5.0,
            );

            $address = $httpRecord['context']['address'] ?? null;
            self::assertIsString($address);

            $address = str_replace('tcp://', '', $address);
            $url = "http://{$address}/metrics";
            $response = @file_get_contents($url);

            self::assertIsString($response);
            self::assertNotSame('{"status":"ok"}', $response);
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testExpectedExitRestartsImmediately(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForWorkerStart($session, 5.0);
            $this->dispatchFixtureMessages(1, 'exit:0');

            $exitRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker exited.'
                    && ($record['context']['exit_code'] ?? null) === 0,
                10.0,
            );
            self::assertSame(0, $exitRecord['context']['exit_code'] ?? null);

            $restartRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker restarting after expected exit.',
                5.0,
            );
            self::assertSame('Worker restarting after expected exit.', $restartRecord['message']);

            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker started.',
                10.0,
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testUnexpectedExitRestartsWithBackoff(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', assertNoWarnings: false);

        try {
            $startRecord = $this->waitForWorkerStart($session, 5.0);
            $pid = $startRecord['context']['pid'] ?? null;
            self::assertIsInt($pid);

            $this->signalPid($pid, SIGKILL);

            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker exited.'
                    && ($record['context']['exit_code'] ?? 0) !== 0,
                5.0,
            );

            $restartRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker restarting after unexpected exit.',
                10.0,
            );

            self::assertGreaterThan(0, $restartRecord['context']['delay_seconds'] ?? 0);
            self::assertSame('warning', $restartRecord['level']);

            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker started.',
                10.0,
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testUnexpectedExitStopsAfterFailureLimit(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', assertNoWarnings: false);

        try {
            $startRecord = $this->waitForWorkerStart($session, 5.0);
            $pid = $startRecord['context']['pid'] ?? null;
            self::assertIsInt($pid);

            for ($attempt = 0; $attempt < 4; $attempt += 1) {
                $this->signalPid($pid, SIGKILL);

                $session->waitForRecord(
                    static fn(array $record): bool => $record['message'] === 'Worker exited.'
                        && ($record['context']['exit_code'] ?? 0) !== 0,
                    5.0,
                );

                if ($attempt === 3) {
                    break;
                }

                $restartRecord = $session->waitForRecord(
                    static fn(array $record): bool => $record['message'] === 'Worker restarting after unexpected exit.',
                    10.0,
                );
                self::assertSame('warning', $restartRecord['level']);

                $startRecord = $session->waitForRecord(
                    static fn(array $record): bool => $record['message'] === 'Worker started.',
                    10.0,
                );
                $pid = $startRecord['context']['pid'] ?? null;
                self::assertIsInt($pid);
            }

            $failureLimitRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker failure limit reached.',
                10.0,
            );
            self::assertSame('error', $failureLimitRecord['level']);
            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Process manager shutting down.',
                10.0,
            );
            self::assertSame(0, $session->waitForExit(10.0));
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testSigtermDrainExitsWithoutSigkillWhenWorkerCooperates(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForWorkerStart($session, 5.0);

            $session->signal(SIGTERM);

            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Sent SIGTERM to worker.',
                5.0,
            );
            self::assertSame(0, $session->waitForExit(5.0));

            foreach ($session->getRecords() as $record) {
                self::assertNotSame(
                    'Sent SIGKILL to worker after shutdown timeout.',
                    $record['message'],
                    'SIGKILL must not be sent when the worker exits cleanly within shutdown_timeout',
                );
            }
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testSigkillEscalatesAfterTimeoutWhenWorkerIgnoresSigterm(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', assertNoWarnings: false);

        try {
            $this->waitForWorkerStart($session, 5.0);
            $this->dispatchFixtureMessages(1, 'sigterm-ignore');

            $this->waitForStdoutJsonLine(
                $session,
                static fn(array $record): bool => ($record['message'] ?? null) === 'Fixture sigterm-ignore handler entered.',
                10.0,
            );

            $session->signal(SIGTERM);

            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Sent SIGTERM to worker.',
                5.0,
            );

            $sigkillRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Sent SIGKILL to worker after shutdown timeout.',
                10.0,
            );
            self::assertSame('warning', $sigkillRecord['level']);
            self::assertSame(2, $sigkillRecord['context']['timeout_seconds'] ?? null);

            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Worker exited.',
                5.0,
            );
            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Process manager shutting down.',
                5.0,
            );
            self::assertSame(0, $session->waitForExit(5.0));
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testWorkerJsonLogsIncludeWorkerId(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForWorkerStart($session, 5.0);
            $payload = 'worker-id-check';
            $this->dispatchFixtureMessages(1, $payload);

            $logLine = $this->waitForStdoutJsonLine(
                $session,
                static fn(array $record): bool => ($record['message'] ?? null) === 'Fixture message handled.'
                    && ($record['context']['payload'] ?? null) === $payload,
                5.0,
            );

            self::assertIsArray($logLine['extra'] ?? null);
            self::assertSame(1, $logLine['extra']['worker_id'] ?? null);
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testMetricsExposeMessengerCountersAndHistogram(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $httpRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'HTTP server listening.',
                5.0,
            );

            $this->waitForWorkerStart($session, 5.0);
            $this->dispatchFixtureMessages(1, 'metrics-probe');

            $this->waitForStdoutJsonLine(
                $session,
                static fn(array $record): bool => ($record['message'] ?? null) === 'Fixture message handled.'
                    && ($record['context']['payload'] ?? null) === 'metrics-probe',
                10.0,
            );

            $address = $httpRecord['context']['address'] ?? null;
            self::assertIsString($address);
            $address = str_replace('tcp://', '', $address);
            $url = "http://{$address}/metrics";

            $body = $this->scrapeUntil(
                $url,
                static fn(string $b): bool => str_contains($b, 'messenger_messages_processed_total')
                    && str_contains($b, 'messenger_message_duration_seconds_bucket')
                    && str_contains($b, 'messenger_messages_in_flight'),
                10.0,
            );

            self::assertStringContainsString('# TYPE messenger_messages_processed_total counter', $body);
            self::assertStringContainsString('# TYPE messenger_message_duration_seconds histogram', $body);
            self::assertStringContainsString('# TYPE messenger_messages_in_flight gauge', $body);
            self::assertStringContainsString('messenger_message_duration_seconds_count', $body);
            self::assertStringContainsString('FixtureMessage', $body);
            self::assertDoesNotMatchRegularExpression('/(^|[^_])messages_processed_total/', $body);
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testWorkerOutputPrefixesNonJsonLines(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForWorkerStart($session, 5.0);
            $this->dispatchFixtureMessages(1, 'stdout:plain');

            $line = $this->waitForStdoutLine(
                $session,
                static fn(string $stdoutLine): bool => str_contains($stdoutLine, '[worker 1] fixture plain output'),
                5.0,
            );

            self::assertSame('[worker 1] fixture plain output', $line);
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testUnexpectedExitBackoffIsExponential(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', assertNoWarnings: false);

        try {
            $startRecord = $this->waitForWorkerStart($session, 5.0);
            $pid = $startRecord['context']['pid'] ?? null;
            self::assertIsInt($pid);

            $delays = [];

            for ($attempt = 1; $attempt <= 3; $attempt += 1) {
                $this->signalPid($pid, SIGKILL);

                $session->waitForRecord(
                    static fn(array $record): bool => $record['message'] === 'Worker exited.'
                        && ($record['context']['exit_code'] ?? 0) !== 0,
                    5.0,
                );

                $restart = $session->waitForRecord(
                    static fn(array $record): bool => $record['message'] === 'Worker restarting after unexpected exit.'
                        && ($record['context']['attempt'] ?? null) === $attempt,
                    10.0,
                );
                $delays[] = $restart['context']['delay_seconds'] ?? null;

                $startRecord = $session->waitForRecord(
                    static fn(array $record): bool => $record['message'] === 'Worker started.',
                    20.0,
                );
                $pid = $startRecord['context']['pid'] ?? null;
                self::assertIsInt($pid);
            }

            // Default backoff: base=1, max=30 → delays = 1, 2, 4 seconds (1 * 2^(attempt-1)).
            self::assertSame([1, 2, 4], $delays);
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testSigtermDrainsInFlightMessageBeforeExit(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $this->waitForWorkerStart($session, 5.0);
            $this->dispatchFixtureMessages(1, 'sleep:2');

            $this->waitForStdoutJsonLine(
                $session,
                static fn(array $record): bool => ($record['message'] ?? null) === 'Fixture sleep handler entered.',
                10.0,
            );

            $session->signal(SIGTERM);

            $sigtermAt = microtime(true);
            $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'Sent SIGTERM to worker.',
                5.0,
            );

            $this->waitForStdoutJsonLine(
                $session,
                static fn(array $record): bool => ($record['message'] ?? null) === 'Fixture message handled.'
                    && ($record['context']['payload'] ?? null) === 'sleep:2',
                10.0,
            );
            $handledAt = microtime(true);

            self::assertGreaterThan(
                0.0,
                $handledAt - $sigtermAt,
                'In-flight message must finish handling AFTER SIGTERM.',
            );

            self::assertSame(0, $session->waitForExit(10.0));

            foreach ($session->getRecords() as $record) {
                self::assertNotSame(
                    'Sent SIGKILL to worker after shutdown timeout.',
                    $record['message'],
                    'Worker should drain in-flight message without SIGKILL.',
                );
            }
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testMetricsExposesMessagesProcessedCounterAfterConsume(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $httpRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'HTTP server listening.',
                5.0,
            );
            $rawAddress = $httpRecord['context']['address'] ?? null;
            self::assertIsString($rawAddress);
            $address = str_replace('tcp://', '', $rawAddress);

            $this->waitForWorkerStart($session, 5.0);

            $payload = 'metrics-counter';
            $messageCount = 3;
            $this->dispatchFixtureMessages($messageCount, $payload);

            for ($i = 0; $i < $messageCount; $i += 1) {
                $session->waitForRecord(
                    static function (array $record) use ($payload): bool {
                        if ($record['message'] !== 'Fixture message handled.') {
                            return false;
                        }
                        $recordPayload = $record['context']['payload'] ?? null;
                        return is_string($recordPayload) && str_starts_with($recordPayload, $payload);
                    },
                    10.0,
                );
            }

            // The supervisor increments the counter on the IPC ProcessedCommandMessage,
            // which arrives slightly after the worker logs "Fixture message handled.".
            // Poll /metrics until the counter reaches the expected value.
            $start = microtime(true);
            $body = '';
            $matched = false;
            while ((microtime(true) - $start) < 5.0) {
                $body = (string) @file_get_contents("http://{$address}/metrics");
                if (preg_match('/messenger_messages_processed_total\{[^}]*transport="async"[^}]*\} (\d+)/', $body, $m) === 1
                    && (int) $m[1] >= $messageCount
                ) {
                    $matched = true;
                    break;
                }
                usleep(100000);
            }

            self::assertTrue(
                $matched,
                sprintf('Expected messenger_messages_processed_total{transport="async"} >= %d. Got: %s', $messageCount, $body),
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testIpcPongUpdatesLastPongTimestampMetric(): void
    {
        // Default ping interval is 50 ticks * 200ms = 10s. Wait for two pong cycles
        // and assert the worker_last_pong_timestamp gauge advances each cycle.
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $httpRecord = $session->waitForRecord(
                static fn(array $record): bool => $record['message'] === 'HTTP server listening.',
                5.0,
            );
            $rawAddress = $httpRecord['context']['address'] ?? null;
            self::assertIsString($rawAddress);
            $address = str_replace('tcp://', '', $rawAddress);

            $this->waitForWorkerStart($session, 5.0);

            $first = $this->waitForLastPongTimestamp($address, minimumValue: 0.0, timeout: 14.0);
            $second = $this->waitForLastPongTimestamp($address, minimumValue: $first + 0.5, timeout: 14.0);

            self::assertGreaterThan($first, $second, 'last_pong_timestamp should advance across ping intervals.');
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function waitForWorkerStart(ConsoleProcessSession $session, float $timeout): array
    {
        return $session->waitForRecord(
            static fn(array $record): bool => $record['message'] === 'Worker started.',
            $timeout,
        );
    }

    private function stopSessionIfRunning(ConsoleProcessSession $session): void
    {
        if ($session->getProcess()->isRunning()) {
            $session->signal(SIGTERM);
            $session->waitForExit(10.0);
        }
    }

    /**
     * @template T
     * @param callable(string): (T|null) $lineProcessor
     * @return T
     */
    private function waitForStdoutLineMatching(ConsoleProcessSession $session, callable $lineProcessor, float $timeout): mixed
    {
        $start = microtime(true);
        $offset = 0;
        $buffer = '';
        $linesProcessed = 0;
        $recentLines = [];

        while ((microtime(true) - $start) < $timeout) {
            $session->collectRecords();
            $stdout = $session->getStdout();
            $length = strlen($stdout);

            if ($length > $offset) {
                $buffer .= substr($stdout, $offset);
                $offset = $length;
            }

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePosition);
                $buffer = substr($buffer, $newlinePosition + 1);
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $linesProcessed++;
                $recentLines[] = $line;
                if (count($recentLines) > 5) {
                    array_shift($recentLines);
                }

                $result = $lineProcessor($line);

                if ($result !== null) {
                    return $result;
                }
            }

            usleep(100000);
        }

        $elapsed = microtime(true) - $start;
        $stderr = $session->getStderr();
        $stderr = $stderr !== '' ? substr($stderr, -500) : '(empty)';

        throw new \RuntimeException(sprintf(
            "Timed out after %.1fs waiting for stdout line.\nLines processed: %d\nLast lines:\n  %s\nStderr (last 500 chars): %s",
            $elapsed,
            $linesProcessed,
            $recentLines !== [] ? implode("\n  ", $recentLines) : '(none)',
            $stderr,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForStdoutJsonLine(ConsoleProcessSession $session, callable $predicate, float $timeout): array
    {
        return $this->waitForStdoutLineMatching(
            $session,
            static function (string $line) use ($predicate): ?array {
                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    return null;
                }

                return $predicate($decoded) ? $decoded : null;
            },
            $timeout,
        );
    }

    private function waitForStdoutLine(ConsoleProcessSession $session, callable $predicate, float $timeout): string
    {
        return $this->waitForStdoutLineMatching(
            $session,
            static function (string $line) use ($predicate): ?string {
                return $predicate($line) ? $line : null;
            },
            $timeout,
        );
    }

    private function scrapeUntil(string $url, callable $predicate, float $timeout): string
    {
        $start = microtime(true);
        $lastBody = '';

        while ((microtime(true) - $start) < $timeout) {
            $body = @file_get_contents($url);

            if (is_string($body)) {
                $lastBody = $body;

                if ($predicate($body)) {
                    return $body;
                }
            }

            usleep(100_000);
        }

        throw new \RuntimeException(sprintf(
            "Timed out scraping %s after %.1fs. Last body:\n%s",
            $url,
            $timeout,
            $lastBody,
        ));
    }

    private function waitForLastPongTimestamp(string $address, float $minimumValue, float $timeout): float
    {
        $start = microtime(true);
        $latest = null;

        while ((microtime(true) - $start) < $timeout) {
            $body = (string) @file_get_contents("http://{$address}/metrics");

            if (preg_match('/worker_last_pong_timestamp\{worker="1"\} ([\d.]+)/', $body, $m) === 1) {
                $value = (float) $m[1];
                if ($value > $minimumValue) {
                    return $value;
                }
                $latest = $value;
            }

            usleep(200000);
        }

        throw new \RuntimeException(sprintf(
            'Timed out after %.1fs waiting for worker_last_pong_timestamp > %.3f. Latest seen: %s',
            $timeout,
            $minimumValue,
            $latest === null ? '(none)' : (string) $latest,
        ));
    }

    private function signalPid(int $pid, int $signal): void
    {
        $process = new Process(['kill', sprintf('-%d', $signal), (string) $pid]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Unable to signal PID %d.', $pid));
        }
    }

    private function dispatchFixtureMessages(int $count, string $payload): void
    {
        $process = new Process([
            PHP_BINARY,
            'tests/Fixtures/app/bin/console',
            'fixture:dispatch',
            sprintf('--count=%d', $count),
            sprintf('--payload=%s', $payload),
        ], $this->getProjectRoot(), $this->getFixtureEnv());

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to dispatch fixture messages.');
        }
    }

    private function prepareDoctrineTransport(): void
    {
        $dbPath = $this->getFixtureProjectDir() . '/var/test.db';

        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        $process = new Process([
            PHP_BINARY,
            'tests/Fixtures/app/bin/console',
            'messenger:setup-transports',
            '--no-interaction',
        ], $this->getProjectRoot(), $this->getFixtureEnv());

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to setup doctrine messenger transport.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function getFixtureEnv(): array
    {
        return array_replace($_ENV, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
        ]);
    }

    private function getProjectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function getFixtureProjectDir(): string
    {
        return $this->getProjectRoot() . '/tests/Fixtures/app';
    }
}
