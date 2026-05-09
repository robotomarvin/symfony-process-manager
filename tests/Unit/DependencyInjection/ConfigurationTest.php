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
    public function testMinimalConsumerWithScalarTransport(): void
    {
        $config = $this->process([
            'consumers' => [
                'async' => ['transports' => 'async'],
            ],
        ]);

        self::assertArrayHasKey('consumers', $config);
        self::assertCount(1, $config['consumers']);
        self::assertArrayHasKey('async', $config['consumers']);

        $consumer = $config['consumers']['async'];
        self::assertSame(['async'], $consumer['transports']);
        self::assertNull($consumer['processes']);
        self::assertSame(3, $consumer['failure_limit']);
        self::assertSame(60, $consumer['failure_window']);
        self::assertSame(1, $consumer['backoff_base']);
        self::assertSame(30, $consumer['backoff_max']);
        self::assertSame(200, $consumer['poll_interval_ms']);
        self::assertArrayNotHasKey('autoscaler', $consumer);

        $consumeArgs = $consumer['consume_args'];
        self::assertNull($consumeArgs['memory_limit']);
        self::assertNull($consumeArgs['time_limit']);
        self::assertNull($consumeArgs['limit']);
        self::assertNull($consumeArgs['sleep']);
        self::assertSame([], $consumeArgs['queues']);
        self::assertSame([], $consumeArgs['extra']);
    }

    public function testMultiTransportConsumerWithListTransports(): void
    {
        $config = $this->process([
            'consumers' => [
                'ingest' => [
                    'transports' => ['orders', 'payments'],
                    'processes' => 2,
                ],
            ],
        ]);

        $consumer = $config['consumers']['ingest'];
        self::assertSame(['orders', 'payments'], $consumer['transports']);
        self::assertSame(2, $consumer['processes']);
    }

    public function testFullConfigWithAllOptionsSpecified(): void
    {
        $config = $this->process([
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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

        $consumer = $config['consumers']['async'];
        self::assertSame(['async'], $consumer['transports']);
        self::assertSame(4, $consumer['processes']);
        self::assertSame(5, $consumer['failure_limit']);
        self::assertSame(120, $consumer['failure_window']);
        self::assertSame(2, $consumer['backoff_base']);
        self::assertSame(60, $consumer['backoff_max']);
        self::assertSame(500, $consumer['poll_interval_ms']);

        $consumeArgs = $consumer['consume_args'];
        self::assertSame(256, $consumeArgs['memory_limit']);
        self::assertSame(3600, $consumeArgs['time_limit']);
        self::assertSame(100, $consumeArgs['limit']);
        self::assertSame(1, $consumeArgs['sleep']);
        self::assertSame(['high', 'low'], $consumeArgs['queues']);
        self::assertSame(['--no-reset'], $consumeArgs['extra']);
    }

    public function testMultipleConsumers(): void
    {
        $config = $this->process([
            'consumers' => [
                'async' => ['transports' => 'async', 'processes' => 2],
                'failed' => ['transports' => 'failed'],
                'priority' => [
                    'transports' => 'priority',
                    'processes' => 3,
                    'consume_args' => [
                        'memory_limit' => 512,
                        'queues' => ['urgent'],
                    ],
                ],
            ],
        ]);

        self::assertCount(3, $config['consumers']);
        self::assertSame(['async'], $config['consumers']['async']['transports']);
        self::assertSame(['failed'], $config['consumers']['failed']['transports']);
        self::assertSame(['priority'], $config['consumers']['priority']['transports']);

        self::assertSame(2, $config['consumers']['async']['processes']);
        self::assertNull($config['consumers']['failed']['processes']);
        self::assertSame(3, $config['consumers']['priority']['processes']);
        self::assertSame(512, $config['consumers']['priority']['consume_args']['memory_limit']);
        self::assertSame(['urgent'], $config['consumers']['priority']['consume_args']['queues']);
    }

    public function testDefaultShutdownTimeoutIs30(): void
    {
        $config = $this->process([
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame(30, $config['shutdown_timeout']);
    }

    public function testCustomShutdownTimeout(): void
    {
        $config = $this->process([
            'shutdown_timeout' => 90,
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame(90, $config['shutdown_timeout']);
    }

    public function testShutdownTimeoutZeroIsValid(): void
    {
        $config = $this->process([
            'shutdown_timeout' => 0,
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame(0, $config['shutdown_timeout']);
    }

    public function testNegativeShutdownTimeoutIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'shutdown_timeout' => -1,
            'consumers' => ['async' => ['transports' => 'async']],
        ]);
    }

    public function testEmptyConsumersThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'consumers' => [],
        ]);
    }

    public function testMissingConsumersThrowsException(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([]);
    }

    public function testMissingTransportsOnConsumerIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'consumers' => [
                'async' => ['processes' => 1],
            ],
        ]);
    }

    public function testEmptyTransportsListIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'consumers' => [
                'async' => ['transports' => []],
            ],
        ]);
    }

    public function testAutoscalerIntervalDefaultsToTen(): void
    {
        $config = $this->process([
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame(10, $config['autoscaler_interval_sec']);
    }

    public function testTotalCapDefaultsToNull(): void
    {
        $config = $this->process([
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertNull($config['total_cap']);
    }

    public function testAutoscalerBlockParses(): void
    {
        $config = $this->process([
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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

        $consumer = $config['consumers']['async'];
        self::assertArrayHasKey('autoscaler', $consumer);
        $autoscaler = $consumer['autoscaler'] ?? null;
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
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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
            'consumers' => [
                'async' => [
                    'transports' => 'async',
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
        $this->expectExceptionMessage('total_cap (3) is below the sum of pool minimums (4): a min=2, b min=2.');

        $this->process([
            'total_cap' => 3,
            'consumers' => [
                'a' => [
                    'transports' => 'a',
                    'autoscaler' => [
                        'min' => 2,
                        'max' => 5,
                        'strategy' => ['type' => 'utilization'],
                    ],
                ],
                'b' => [
                    'transports' => 'b',
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
        $this->expectExceptionMessage('total_cap (3) is below the sum of pool minimums (4): fixed processes=2, auto min=2.');

        $this->process([
            'total_cap' => 3,
            'consumers' => [
                'fixed' => ['transports' => 'fixed', 'processes' => 2],
                'auto' => [
                    'transports' => 'auto',
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
            'consumers' => [
                'fixed' => ['transports' => 'fixed', 'processes' => 2],
                'auto' => [
                    'transports' => 'auto',
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

    public function testMessagesMetricsDefaults(): void
    {
        $config = $this->process([
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        $messages = $config['metrics']['messages'];
        self::assertTrue($messages['enabled']);
        self::assertSame([], $messages['whitelist']);
        self::assertSame([0.01, 0.05, 0.1, 0.5, 1.0, 5.0, 10.0, 30.0, 60.0], $messages['duration_buckets']);
    }

    public function testMessagesMetricsCanBeDisabled(): void
    {
        $config = $this->process([
            'metrics' => ['messages' => ['enabled' => false]],
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertFalse($config['metrics']['messages']['enabled']);
    }

    public function testWhitelistAcceptsExactAndGlobEntries(): void
    {
        $config = $this->process([
            'metrics' => ['messages' => ['whitelist' => ['App\\Foo', 'App\\Email\\*']]],
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame(['App\\Foo', 'App\\Email\\*'], $config['metrics']['messages']['whitelist']);
    }

    public function testCustomDurationBucketsAreSortedAndDeduped(): void
    {
        $config = $this->process([
            'metrics' => ['messages' => ['duration_buckets' => [1.0, 0.5, 0.5, 0.1]]],
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame([0.1, 0.5, 1.0], $config['metrics']['messages']['duration_buckets']);
    }

    public function testEmptyDurationBucketsIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'metrics' => ['messages' => ['duration_buckets' => []]],
            'consumers' => ['async' => ['transports' => 'async']],
        ]);
    }

    public function testHttpServerPortZeroIsValid(): void
    {
        $config = $this->process([
            'http_server' => ['port' => 0],
            'consumers' => ['async' => ['transports' => 'async']],
        ]);

        self::assertSame(0, $config['http_server']['port']);
    }

    public function testHttpServerNegativePortIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'http_server' => ['port' => -1],
            'consumers' => ['async' => ['transports' => 'async']],
        ]);
    }

    public function testScaleUpThresholdAboveOneIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'consumers' => [
                'async' => [
                    'transports' => 'async',
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 5,
                        'strategy' => [
                            'type' => 'utilization',
                            'scale_up_threshold' => 1.5,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testScaleDownThresholdBelowZeroIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            'consumers' => [
                'async' => [
                    'transports' => 'async',
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 5,
                        'strategy' => [
                            'type' => 'utilization',
                            'scale_down_threshold' => -0.1,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testScaleDownAboveScaleUpIsInvalid(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('strategy.scale_down_threshold must be <= scale_up_threshold.');

        $this->process([
            'consumers' => [
                'async' => [
                    'transports' => 'async',
                    'autoscaler' => [
                        'min' => 1,
                        'max' => 5,
                        'strategy' => [
                            'type' => 'utilization',
                            'scale_up_threshold' => 0.7,
                            'scale_down_threshold' => 0.9,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     shutdown_timeout: int,
     *     total_cap: ?int,
     *     autoscaler_interval_sec: int,
     *     http_server: array{host: string, port: int},
     *     metrics: array{messages: array{enabled: bool, whitelist: list<string>, duration_buckets: list<float>}},
     *     consumers: array<string, array{
     *         transports: list<string>,
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
         *     metrics: array{messages: array{enabled: bool, whitelist: list<string>, duration_buckets: list<float>}},
         *     consumers: array<string, array{
         *         transports: list<string>,
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
