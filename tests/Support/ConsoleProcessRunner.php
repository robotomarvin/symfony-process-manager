<?php

namespace SymfonyProcessManager\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ConsoleProcessRunner
{
    private const WARNING_LEVELS = [
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    private const LOG_LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    private const DEFAULT_TIMEOUT = 10;

    /**
     * @param array<int, string> $arguments
     */
    public function run(string $command, array $arguments = []): ConsoleProcessResult
    {
        $projectRoot = dirname(__DIR__, 2);
        $process = new Process(
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
