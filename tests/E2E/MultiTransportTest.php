<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Command\ServeCommand;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\Tests\Support\ConsoleProcessRunner;
use SymfonyProcessManager\Tests\Support\ConsoleProcessSession;

#[CoversClass(ServeCommand::class)]
#[CoversClass(ProcessManagerLoop::class)]
#[CoversClass(HttpServer::class)]
final class MultiTransportTest extends TestCase
{
    protected function setUp(): void
    {
        $this->prepareDoctrineTransport();
    }

    public function testMultiTransportConsumerExposesPerTransportMetrics(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve');

        try {
            $address = $this->waitForHttpAddress($session);

            // The multi-transport consumer must boot one worker that consumes
            // both multi_a and multi_b in a single messenger:consume process.
            $startRecord = $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'Worker started.'
                    && ($r['context']['consumer'] ?? null) === 'multi',
                5.0,
            );
            self::assertSame(['multi_a', 'multi_b'], $startRecord['context']['transports'] ?? null);

            // One dispatch fans out to both transports because of the multi-transport
            // routing in framework.yaml. The single multi-consumer worker handles both.
            $this->dispatchMultiTransportMessage('multi-test');

            $session->waitForRecord(
                static fn(array $r): bool => $r['message'] === 'MultiTransport message handled.'
                    && ($r['context']['payload'] ?? null) === 'multi-test',
                10.0,
            );

            // Wait for both transport-labelled counter series to reach 1 in /metrics
            // and for the pool-level autoscaler gauge to appear. The gauge is emitted
            // once at boot (before the first periodic tick) and refreshed every
            // autoscaler_interval_sec; checking it here keeps the assertion below
            // independent of scrape ordering.
            $deadline = microtime(true) + 10.0;
            $metrics = '';
            $aSeen = false;
            $bSeen = false;
            $gaugeSeen = false;
            while (microtime(true) < $deadline) {
                $metrics = $this->fetchMetrics($address);
                $aSeen = (bool) preg_match('/messages_processed_total\{consumer="multi",transport="multi_a"\} 1\b/', $metrics);
                $bSeen = (bool) preg_match('/messages_processed_total\{consumer="multi",transport="multi_b"\} 1\b/', $metrics);
                $gaugeSeen = (bool) preg_match('/autoscaler_current_workers\{consumer="multi"\} \d+/', $metrics);

                if ($aSeen && $bSeen && $gaugeSeen) {
                    break;
                }

                usleep(200_000);
            }

            self::assertTrue($aSeen, "missing messages_processed_total for transport=multi_a in /metrics:\n" . $metrics);
            self::assertTrue($bSeen, "missing messages_processed_total for transport=multi_b in /metrics:\n" . $metrics);
            self::assertTrue($gaugeSeen, "missing autoscaler_current_workers gauge for consumer=multi in /metrics:\n" . $metrics);

            // Supervisor lifecycle counters are consumer-only (no transport label).
            self::assertMatchesRegularExpression(
                '/worker_starts_total\{consumer="multi"\} \d+/',
                $metrics,
            );
            self::assertDoesNotMatchRegularExpression(
                '/worker_starts_total\{consumer="multi",transport=/',
                $metrics,
            );

            // Pool-level autoscaler gauges remain consumer-keyed (no transport label).
            self::assertMatchesRegularExpression(
                '/autoscaler_current_workers\{consumer="multi"\} 1/',
                $metrics,
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    private function waitForHttpAddress(ConsoleProcessSession $session): string
    {
        $record = $session->waitForRecord(
            static fn(array $r): bool => $r['message'] === 'HTTP server listening.',
            5.0,
        );

        $address = $record['context']['address'] ?? null;
        self::assertIsString($address);

        return str_replace('tcp://', '', $address);
    }

    private function fetchMetrics(string $address): string
    {
        $url = "http://{$address}/metrics";
        $body = @file_get_contents($url);

        self::assertIsString($body);

        return $body;
    }

    private function dispatchMultiTransportMessage(string $payload): void
    {
        $process = new Process([
            PHP_BINARY,
            'tests/Fixtures/app/bin/console',
            'fixture:dispatch-multi',
            sprintf('--payload=%s', $payload),
        ], $this->getProjectRoot(), $this->getFixtureEnv());

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Unable to dispatch multi-transport fixture message: ' . $process->getErrorOutput());
        }
    }

    private function stopSessionIfRunning(ConsoleProcessSession $session): void
    {
        if ($session->getProcess()->isRunning()) {
            $session->signal(SIGTERM);
            $session->waitForExit(10.0);
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
