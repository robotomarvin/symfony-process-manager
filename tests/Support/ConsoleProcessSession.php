<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ConsoleProcessSession
{
    /**
     * @var array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    private array $records = [];
    private int $nextRecordIndex = 0;
    private string $stdoutBuffer = '';
    private string $stdout = '';
    private string $stderr = '';

    public function __construct(
        private readonly Process $process,
        private readonly bool $assertNoWarnings = true,
    ) {}

    public function getProcess(): Process
    {
        return $this->process;
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }

    public function getStderr(): string
    {
        return $this->stderr;
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public function collectRecords(): array
    {
        $newRecords = [];
        $output = $this->process->getIncrementalOutput();
        $errorOutput = $this->process->getIncrementalErrorOutput();

        if ($output !== '') {
            $this->stdout .= $output;
            $this->stdoutBuffer .= $output;
        }

        if ($errorOutput !== '') {
            $this->stderr .= $errorOutput;
        }

        while (true) {
            $newlinePosition = strpos($this->stdoutBuffer, "\n");

            if ($newlinePosition === false) {
                break;
            }

            $line = trim(substr($this->stdoutBuffer, 0, $newlinePosition));
            $this->stdoutBuffer = substr($this->stdoutBuffer, $newlinePosition + 1);

            if ($line === '') {
                continue;
            }

            $record = JsonLogParser::parseRecord($line);

            if ($record === null) {
                continue;
            }

            if ($this->assertNoWarnings) {
                JsonLogParser::assertNoWarnings([$record]);
            }
            $this->records[] = $record;
            $newRecords[] = $record;
        }

        return $newRecords;
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    public function waitForRecord(callable $predicate, float $timeoutSeconds): array
    {
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeoutSeconds) {
            $this->collectRecords();

            $recordsCount = count($this->records);

            while ($this->nextRecordIndex < $recordsCount) {
                $record = $this->records[$this->nextRecordIndex];
                $this->nextRecordIndex += 1;

                if ($predicate($record)) {
                    return $record;
                }
            }

            usleep(100000);
        }

        $elapsed = microtime(true) - $start;
        $lastRecords = array_slice($this->records, -5);
        $recordsSummary = array_map(
            static fn(array $r): string => sprintf('[%s] %s', $r['level'], $r['message']),
            $lastRecords,
        );
        $stderr = $this->stderr !== '' ? substr($this->stderr, -500) : '(empty)';
        $exitCode = $this->process->isRunning() ? 'still running' : (string) ($this->process->getExitCode() ?? 'unknown');

        throw new RuntimeException(sprintf(
            "Timed out after %.1fs waiting for log record.\nTotal records: %d\nLast records:\n  %s\nProcess: %s\nStderr (last 500 chars): %s",
            $elapsed,
            count($this->records),
            $recordsSummary !== [] ? implode("\n  ", $recordsSummary) : '(none)',
            $exitCode === 'still running' ? 'still running' : sprintf('exited with code %s', $exitCode),
            $stderr,
        ));
    }

    public function waitForExit(float $timeoutSeconds): int
    {
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeoutSeconds) {
            $this->collectRecords();

            if (!$this->process->isRunning()) {
                return $this->process->getExitCode() ?? 0;
            }

            usleep(100000);
        }

        $elapsed = microtime(true) - $start;
        $stderr = $this->stderr !== '' ? substr($this->stderr, -500) : '(empty)';

        throw new RuntimeException(sprintf(
            "Timed out after %.1fs waiting for process to exit.\nTotal records: %d\nProcess: %s\nStderr (last 500 chars): %s",
            $elapsed,
            count($this->records),
            $this->process->isRunning() ? 'still running' : sprintf('exited with code %s', $this->process->getExitCode() ?? 'unknown'),
            $stderr,
        ));
    }

    public function signal(int $signal): void
    {
        $this->process->signal($signal);
    }

}
