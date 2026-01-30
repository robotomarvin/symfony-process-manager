<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use SymfonyProcessManager\DependencyInjection\Configuration;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testMinimalConfigWithOneTransportUsesDefaults(): void
    {
        $config = $this->process([
            'transports' => [
                'async' => null,
            ],
        ]);

        self::assertArrayHasKey('transports', $config);
        self::assertCount(1, $config['transports']);
        self::assertArrayHasKey('async', $config['transports']);

        $transport = $config['transports']['async'];
        self::assertSame(1, $transport['processes']);
        self::assertSame(3, $transport['failure_limit']);
        self::assertSame(60, $transport['failure_window']);
        self::assertSame(1, $transport['backoff_base']);
        self::assertSame(30, $transport['backoff_max']);
        self::assertSame(200, $transport['poll_interval_ms']);

        $consumeArgs = $transport['consume_args'];
        self::assertNull($consumeArgs['memory_limit']);
        self::assertNull($consumeArgs['time_limit']);
        self::assertNull($consumeArgs['limit']);
        self::assertNull($consumeArgs['sleep']);
        self::assertSame([], $consumeArgs['queues']);
        self::assertSame([], $consumeArgs['extra']);
    }

    public function testFullConfigWithAllOptionsSpecified(): void
    {
        $config = $this->process([
            'transports' => [
                'async' => [
                    'processes' => 4,
                    'failure_limit' => 5,
                    'failure_window' => 120,
                    'backoff_base' => 2,
                    'backoff_max' => 60,
                    'poll_interval_ms' => 500,
                    'consume_args' => [
                        'memory_limit' => 256,
                        'time_limit' => 3600,
                        'limit' => 100,
                        'sleep' => 1,
                        'queues' => ['high', 'low'],
                        'extra' => ['--no-reset'],
                    ],
                ],
            ],
        ]);

        $transport = $config['transports']['async'];
        self::assertSame(4, $transport['processes']);
        self::assertSame(5, $transport['failure_limit']);
        self::assertSame(120, $transport['failure_window']);
        self::assertSame(2, $transport['backoff_base']);
        self::assertSame(60, $transport['backoff_max']);
        self::assertSame(500, $transport['poll_interval_ms']);

        $consumeArgs = $transport['consume_args'];
        self::assertSame(256, $consumeArgs['memory_limit']);
        self::assertSame(3600, $consumeArgs['time_limit']);
        self::assertSame(100, $consumeArgs['limit']);
        self::assertSame(1, $consumeArgs['sleep']);
        self::assertSame(['high', 'low'], $consumeArgs['queues']);
        self::assertSame(['--no-reset'], $consumeArgs['extra']);
    }

    public function testMultipleTransports(): void
    {
        $config = $this->process([
            'transports' => [
                'async' => [
                    'processes' => 2,
                ],
                'failed' => null,
                'priority' => [
                    'processes' => 3,
                    'consume_args' => [
                        'memory_limit' => 512,
                        'queues' => ['urgent'],
                    ],
                ],
            ],
        ]);

        self::assertCount(3, $config['transports']);
        self::assertArrayHasKey('async', $config['transports']);
        self::assertArrayHasKey('failed', $config['transports']);
        self::assertArrayHasKey('priority', $config['transports']);

        self::assertSame(2, $config['transports']['async']['processes']);
        self::assertSame(1, $config['transports']['failed']['processes']);
        self::assertSame(3, $config['transports']['priority']['processes']);
        self::assertSame(512, $config['transports']['priority']['consume_args']['memory_limit']);
        self::assertSame(['urgent'], $config['transports']['priority']['consume_args']['queues']);
    }

    public function testEmptyTransportsThrowsException(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->process([
            'transports' => [],
        ]);
    }

    public function testMissingTransportsThrowsException(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->process([]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{transports: array<string, array{processes: int, failure_limit: int, failure_window: int, backoff_base: int, backoff_max: int, poll_interval_ms: int, consume_args: array{memory_limit: ?int, time_limit: ?int, limit: ?int, sleep: ?int, queues: list<string>, extra: list<string>}}>}
     */
    private function process(array $config): array
    {
        $processor = new Processor();

        /** @var array{transports: array<string, array{processes: int, failure_limit: int, failure_window: int, backoff_base: int, backoff_max: int, poll_interval_ms: int, consume_args: array{memory_limit: ?int, time_limit: ?int, limit: ?int, sleep: ?int, queues: list<string>, extra: list<string>}}>} */
        return $processor->processConfiguration(new Configuration(), [$config]);
    }
}
