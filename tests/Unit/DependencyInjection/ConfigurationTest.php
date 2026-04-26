<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
        self::assertNull($transport['processes']);
        self::assertSame(3, $transport['failure_limit']);
        self::assertSame(60, $transport['failure_window']);
        self::assertSame(1, $transport['backoff_base']);
        self::assertSame(30, $transport['backoff_max']);
        self::assertSame(200, $transport['poll_interval_ms']);
        self::assertArrayNotHasKey('autoscaler', $transport);

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
        self::assertNull($config['transports']['failed']['processes']);
        self::assertSame(3, $config['transports']['priority']['processes']);
        self::assertSame(512, $config['transports']['priority']['consume_args']['memory_limit']);
        self::assertSame(['urgent'], $config['transports']['priority']['consume_args']['queues']);
    }

    public function testDefaultShutdownTimeoutIs30(): void
    {
        $config = $this->process([
            'transports' => [
                'async' => null,
            ],
        ]);

        self::assertSame(30, $config['shutdown_timeout']);
    }

    public function testCustomShutdownTimeout(): void
    {
        $config = $this->process([
            'shutdown_timeout' => 90,
            'transports' => [
                'async' => null,
            ],
        ]);

        self::assertSame(90, $config['shutdown_timeout']);
    }

    public function testShutdownTimeoutZeroIsValid(): void
    {
        $config = $this->process([
            'shutdown_timeout' => 0,
            'transports' => [
                'async' => null,
            ],
        ]);

        self::assertSame(0, $config['shutdown_timeout']);
    }

    public function testNegativeShutdownTimeoutIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'shutdown_timeout' => -1,
            'transports' => [
                'async' => null,
            ],
        ]);
    }

    public function testEmptyTransportsThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'transports' => [],
        ]);
    }

    public function testMissingTransportsThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([]);
    }

    public function testAutoscalerIntervalDefaultsToTen(): void
    {
        $config = $this->process([
            'transports' => ['async' => null],
        ]);

        self::assertSame(10, $config['autoscaler_interval_sec']);
    }

    public function testTotalCapDefaultsToNull(): void
    {
        $config = $this->process([
            'transports' => ['async' => null],
        ]);

        self::assertNull($config['total_cap']);
    }

    public function testAutoscalerBlockParses(): void
    {
        $config = $this->process([
            'transports' => [
                'async' => [
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 10,
                        'priority' => 5,
                        'smoothing_window_sec' => 60,
                        'scale_up_cooldown_sec' => 15,
                        'scale_down_cooldown_sec' => 600,
                        'scale_up_step' => 3,
                        'scale_down_step' => 2,
                        'strategy' => [
                            'type' => 'utilization',
                            'target' => 0.8,
                        ],
                    ],
                ],
            ],
        ]);

        $transport = $config['transports']['async'];
        self::assertArrayHasKey('autoscaler', $transport);
        $autoscaler = $transport['autoscaler'] ?? null;
        self::assertNotNull($autoscaler);
        self::assertSame(1, $autoscaler['min']);
        self::assertSame(10, $autoscaler['max']);
        self::assertSame(5, $autoscaler['priority']);
        self::assertSame(60, $autoscaler['smoothing_window_sec']);
        self::assertSame(15, $autoscaler['scale_up_cooldown_sec']);
        self::assertSame(600, $autoscaler['scale_down_cooldown_sec']);
        self::assertSame(3, $autoscaler['scale_up_step']);
        self::assertSame(2, $autoscaler['scale_down_step']);
        self::assertSame('utilization', $autoscaler['strategy']['type']);
        self::assertArrayHasKey('target', $autoscaler['strategy']);
        self::assertSame(0.8, $autoscaler['strategy']['target'] ?? null);
    }

    public function testAutoscalerWithoutMinIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'transports' => [
                'async' => [
                    'autoscaler' => [
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
            ],
        ]);
    }

    public function testAutoscalerMinGreaterThanMaxIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'transports' => [
                'async' => [
                    'autoscaler' => [
                        'min' => 10,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
            ],
        ]);
    }

    public function testProcessesAndAutoscalerAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'transports' => [
                'async' => [
                    'processes' => 3,
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
            ],
        ]);
    }

    public function testServiceStrategyRequiresId(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'transports' => [
                'async' => [
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 5,
                        'strategy' => ['type' => 'service'],
                    ],
                ],
            ],
        ]);
    }

    public function testUnknownStrategyTypeIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'transports' => [
                'async' => [
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 5,
                        'strategy' => ['type' => 'magic'],
                    ],
                ],
            ],
        ]);
    }

    public function testTotalCapBelowMinSumIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'total_cap' => 3,
            'transports' => [
                'a' => [
                    'autoscaler' => [
                        'min' => 2,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
                'b' => [
                    'autoscaler' => [
                        'min' => 2,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
            ],
        ]);
    }

    public function testTotalCapAccountsForFixedProcesses(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'total_cap' => 3,
            'transports' => [
                'fixed' => ['processes' => 2],
                'auto' => [
                    'autoscaler' => [
                        'min' => 2,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
            ],
        ]);
    }

    public function testTotalCapMeetingMinSumIsValid(): void
    {
        $config = $this->process([
            'total_cap' => 4,
            'transports' => [
                'fixed' => ['processes' => 2],
                'auto' => [
                    'autoscaler' => [
                        'min' => 2,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
            ],
        ]);

        self::assertSame(4, $config['total_cap']);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     shutdown_timeout: int,
     *     total_cap: ?int,
     *     autoscaler_interval_sec: int,
     *     http_server: array{host: string, port: int},
     *     transports: array<string, array{
     *         processes: ?int,
     *         failure_limit: int,
     *         failure_window: int,
     *         backoff_base: int,
     *         backoff_max: int,
     *         poll_interval_ms: int,
     *         autoscaler?: array{
     *             min: int,
     *             max: int,
     *             priority: int,
     *             smoothing_window_sec: int,
     *             scale_up_cooldown_sec: int,
     *             scale_down_cooldown_sec: int,
     *             scale_up_step: int,
     *             scale_down_step: int,
     *             strategy: array{type: string, target?: ?float, id?: ?string}
     *         },
     *         consume_args: array{
     *             memory_limit: ?int,
     *             time_limit: ?int,
     *             limit: ?int,
     *             sleep: ?int,
     *             queues: list<string>,
     *             extra: list<string>
     *         }
     *     }>
     * }
     */
    private function process(array $config): array
    {
        $processor = new Processor();

        /** @var array{
         *     shutdown_timeout: int,
         *     total_cap: ?int,
         *     autoscaler_interval_sec: int,
         *     http_server: array{host: string, port: int},
         *     transports: array<string, array{
         *         processes: ?int,
         *         failure_limit: int,
         *         failure_window: int,
         *         backoff_base: int,
         *         backoff_max: int,
         *         poll_interval_ms: int,
         *         autoscaler?: array{
         *             min: int,
         *             max: int,
         *             priority: int,
         *             smoothing_window_sec: int,
         *             scale_up_cooldown_sec: int,
         *             scale_down_cooldown_sec: int,
         *             scale_up_step: int,
         *             scale_down_step: int,
         *             strategy: array{type: string, target?: ?float, id?: ?string}
         *         },
         *         consume_args: array{
         *             memory_limit: ?int,
         *             time_limit: ?int,
         *             limit: ?int,
         *             sleep: ?int,
         *             queues: list<string>,
         *             extra: list<string>
         *         }
         *     }>
         * } */
        return $processor->processConfiguration(new Configuration(), [$config]);
    }
}
