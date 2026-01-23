<?php

namespace SymfonyProcessManager\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use SymfonyProcessManager\Tests\Support\ConsoleProcessRunner;
use SymfonyProcessManager\Tests\Support\ConsoleProcessSession;

require_once __DIR__ . '/../Support/ConsoleProcessRunner.php';

#[CoversClass(ConsoleProcessRunner::class)]
final class ProcessCommandTest extends TestCase
{
    protected function setUp(): void
    {
        $this->prepareDoctrineTransport();
    }

    public function testProcessCommandStartsAndStopsOnSigterm(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', ['--workers=1']);

        try {
            $this->waitForWorkerStart($session, 5.0);
            $session->signal(SIGTERM);
            $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Process manager shutting down.',
                5.0
            );
            self::assertSame(0, $session->waitForExit(5.0));
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testExpectedExitRestartsImmediately(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', ['--workers=1', '--worker-message-limit=1']);

        try {
            $this->waitForWorkerStart($session, 5.0);
            $this->dispatchFixtureMessages(1, 'message');

            $exitRecord = $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker exited.'
                    && ($record['context']['exit_code'] ?? null) === 0,
                10.0
            );
            self::assertSame(0, $exitRecord['context']['exit_code'] ?? null);

            $restartRecord = $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker restarting after expected exit.',
                5.0
            );
            self::assertSame('Worker restarting after expected exit.', $restartRecord['message']);

            $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker started.',
                10.0
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testUnexpectedExitRestartsWithBackoff(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', ['--workers=1']);

        try {
            $startRecord = $this->waitForWorkerStart($session, 5.0);
            $pid = $startRecord['context']['pid'] ?? null;
            self::assertIsInt($pid);

            $this->signalPid($pid, SIGKILL);

            $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker exited.'
                    && ($record['context']['exit_code'] ?? 0) !== 0,
                5.0
            );

            $restartRecord = $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker restarting after unexpected exit.',
                10.0
            );

            self::assertGreaterThan(0, $restartRecord['context']['delay_seconds'] ?? 0);

            $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker started.',
                10.0
            );
        } finally {
            $this->stopSessionIfRunning($session);
        }
    }

    public function testUnexpectedExitStopsAfterFailureLimit(): void
    {
        $runner = new ConsoleProcessRunner();
        $session = $runner->start('pm:serve', ['--workers=1']);

        try {
            $startRecord = $this->waitForWorkerStart($session, 5.0);
            $pid = $startRecord['context']['pid'] ?? null;
            self::assertIsInt($pid);

            for ($attempt = 0; $attempt < 4; $attempt += 1) {
                $this->signalPid($pid, SIGKILL);

                $session->waitForRecord(
                    static fn (array $record): bool => $record['message'] === 'Worker exited.'
                        && ($record['context']['exit_code'] ?? 0) !== 0,
                    5.0
                );

                if ($attempt === 3) {
                    break;
                }

                $session->waitForRecord(
                    static fn (array $record): bool => $record['message'] === 'Worker restarting after unexpected exit.',
                    10.0
                );

                $startRecord = $session->waitForRecord(
                    static fn (array $record): bool => $record['message'] === 'Worker started.',
                    10.0
                );
                $pid = $startRecord['context']['pid'] ?? null;
                self::assertIsInt($pid);
            }

            $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Worker failure limit reached.',
                10.0
            );
            $session->waitForRecord(
                static fn (array $record): bool => $record['message'] === 'Process manager shutting down.',
                10.0
            );
            self::assertSame(0, $session->waitForExit(10.0));
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
            static fn (array $record): bool => $record['message'] === 'Worker started.',
            $timeout
        );
    }

    private function stopSessionIfRunning(ConsoleProcessSession $session): void
    {
        if ($session->getProcess()->isRunning()) {
            $session->signal(SIGTERM);
            $session->waitForExit(10.0);
        }
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
