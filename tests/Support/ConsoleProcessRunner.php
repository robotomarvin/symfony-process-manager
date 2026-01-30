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
     */
    public function run(string $command, array $arguments = [], bool $assertNoWarnings = true): ConsoleProcessResult
    {
        $process = $this->createProcess($command, $arguments);

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
     */
    public function start(string $command, array $arguments = [], bool $assertNoWarnings = true): ConsoleProcessSession
    {
        $process = $this->createProcess($command, $arguments);
        $process->setTimeout(self::DEFAULT_TIMEOUT);
        $process->start();

        return new ConsoleProcessSession($process, $assertNoWarnings);
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
            ]),
        );
    }

}
