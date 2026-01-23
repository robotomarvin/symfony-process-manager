<?php

namespace SymfonyProcessManager\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ConsoleProcessRunner
{
    public const WARNING_LEVELS = [
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    public const LOG_LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    private const DEFAULT_TIMEOUT = 30;

    /**
     * @param array<int, string> $arguments
     */
    public function run(string $command, array $arguments = []): ConsoleProcessResult
    {
        $process = $this->createProcess($command, $arguments);

        $process->setTimeout(self::DEFAULT_TIMEOUT);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $records = $this->parseJsonLogs($stdout);

        $this->assertNoWarnings($records);

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Console process failed with exit code %d. Stderr: %s',
                $process->getExitCode(),
                $stderr
            ));
        }

        return new ConsoleProcessResult(
            $process->getExitCode() ?? 0,
            $stdout,
            $stderr,
            $records
        );
    }

    /**
     * @param array<int, string> $arguments
     */
    public function start(string $command, array $arguments = []): ConsoleProcessSession
    {
        $process = $this->createProcess($command, $arguments);
        $process->setTimeout(self::DEFAULT_TIMEOUT);
        $process->start();

        return new ConsoleProcessSession($process);
    }

    /**
     * @param array<int, string> $arguments
     */
    private function createProcess(string $command, array $arguments): Process
    {
        $projectRoot = dirname(__DIR__, 2);

        return new Process(
            array_merge([
                PHP_BINARY,
                'tests/Fixtures/app/bin/console',
                $command,
            ], $arguments),
            $projectRoot,
            array_replace($_ENV, [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '1',
            ])
        );
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    private function parseJsonLogs(string $stdout): array
    {
        $records = [];

        $lines = preg_split('/\r?\n/', $stdout);

        if ($lines === false) {
            throw new RuntimeException('Unable to split stdout into log lines.');
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded) || !isset($decoded['level'])) {
                throw new RuntimeException(sprintf('Unable to parse log line: %s', $line));
            }

            $records[] = [
                'level' => (string) $decoded['level'],
                'message' => isset($decoded['message']) ? (string) $decoded['message'] : '',
                'context' => isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [],
            ];
        }

        return $records;
    }

    /**
     * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $records
     */
    private function assertNoWarnings(array $records): void
    {
        $violations = [];

        foreach ($records as $record) {
            $level = $record['level'];
            $severity = self::LOG_LEVELS[$level] ?? null;

            if ($severity === null) {
                continue;
            }

            if ($severity >= self::WARNING_LEVELS['warning']) {
                $violations[] = sprintf('%s: %s', $level, $record['message']);
            }
        }

        if ($violations !== []) {
            throw new RuntimeException(sprintf(
                "Detected warning+ log entries:\n%s",
                implode("\n", $violations)
            ));
        }
    }
}

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

    public function __construct(private readonly Process $process)
    {
    }

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

            $decoded = json_decode($line, true);

            if (!is_array($decoded) || !isset($decoded['level'])) {
                throw new RuntimeException(sprintf('Unable to parse log line: %s', $line));
            }

            $record = [
                'level' => (string) $decoded['level'],
                'message' => isset($decoded['message']) ? (string) $decoded['message'] : '',
                'context' => isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [],
            ];

            $this->assertNoWarnings([$record]);
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

        throw new RuntimeException('Timed out waiting for log record.');
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

        throw new RuntimeException('Timed out waiting for process to exit.');
    }

    public function signal(int $signal): void
    {
        $this->process->signal($signal);
    }

    /**
     * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $records
     */
    private function assertNoWarnings(array $records): void
    {
        $violations = [];

        foreach ($records as $record) {
            $level = $record['level'];
            $severity = ConsoleProcessRunner::LOG_LEVELS[$level] ?? null;

            if ($severity === null) {
                continue;
            }

            if ($severity >= ConsoleProcessRunner::WARNING_LEVELS['warning']) {
                $violations[] = sprintf('%s: %s', $level, $record['message']);
            }
        }

        if ($violations !== []) {
            throw new RuntimeException(sprintf(
                "Detected warning+ log entries:\n%s",
                implode("\n", $violations)
            ));
        }
    }
}

final class ConsoleProcessResult
{
    /**
     * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $records
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $records
    ) {
    }
}
