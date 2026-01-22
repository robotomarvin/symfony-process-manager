<?php

namespace SymfonyProcessManager\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SymfonyProcessManager\Tests\Support\ConsoleProcessRunner;

require_once __DIR__ . '/../Support/ConsoleProcessRunner.php';

#[CoversClass(ConsoleProcessRunner::class)]
final class ProcessCommandTest extends TestCase
{
    public function testProcessCommandRunsWithoutWarnings(): void
    {
        $runner = new ConsoleProcessRunner();
        $result = $runner->run('pm:serve');

        self::assertSame(0, $result->exitCode);

        $levels = array_map(
            static fn (array $record): string => $record['level'],
            $result->records
        );

        self::assertContains('info', $levels);
    }
}
