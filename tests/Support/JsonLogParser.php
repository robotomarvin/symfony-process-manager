<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Support;

use RuntimeException;

final class JsonLogParser
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

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}|null
     */
    public static function parseRecord(string $line): ?array
    {
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        $decoded = json_decode($line, true);

        if (!is_array($decoded) || !isset($decoded['level'])) {
            return null;
        }

        return [
            'level' => (string) $decoded['level'],
            'message' => isset($decoded['message']) ? (string) $decoded['message'] : '',
            'context' => isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [],
        ];
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    public static function parseRecords(string $stdout): array
    {
        $records = [];

        $lines = preg_split('/\r?\n/', $stdout);

        if ($lines === false) {
            throw new RuntimeException('Unable to split stdout into log lines.');
        }

        foreach ($lines as $line) {
            $record = self::parseRecord($line);

            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<int, array{level: string, message: string, context: array<string, mixed>}> $records
     */
    public static function assertNoWarnings(array $records): void
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
                implode("\n", $violations),
            ));
        }
    }
}
