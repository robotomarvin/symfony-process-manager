<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class ConsoleProcessRunner
{
    private const DEFAULT_TIMEOUT = 30;

    /**
     * @param array<int, string> $arguments
     * @param array<string, string> $env
     */
    public function run(string $command, array $arguments = [], bool $assertNoWarnings = true, array $env = []): ConsoleProcessResult
    {
        $process = $this->createProcess($command, $arguments, $env);

        $process->setTimeout(self::DEFAULT_TIMEOUT);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $records = JsonLogParser::parseRecords($stdout);

        if ($assertNoWarnings) {
            JsonLogParser::assertNoWarnings($records);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Console process failed with exit code %d. Stderr: %s',
                $process->getExitCode(),
                $stderr,
            ));
        }

        return new ConsoleProcessResult(
            $process->getExitCode() ?? 0,
            $stdout,
            $stderr,
            $records,
        );
    }

    /**
     * @param array<int, string> $arguments
     * @param array<string, string> $env
     */
    public function start(string $command, array $arguments = [], bool $assertNoWarnings = true, array $env = []): ConsoleProcessSession
    {
        $process = $this->createProcess($command, $arguments, $env);
        $process->setTimeout(self::DEFAULT_TIMEOUT);
        $process->start();

        return new ConsoleProcessSession($process, $assertNoWarnings);
    }

    /**
     * @param array<int, string> $arguments
     * @param array<string, string> $env
     */
    private function createProcess(string $command, array $arguments, array $env = []): Process
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
            ], $env),
        );
    }

}
