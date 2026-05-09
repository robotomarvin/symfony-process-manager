<?php

declare(strict_types=1);

namespace SymfonyProcessManager\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use SymfonyProcessManager\Autoscaler\Arbiter\PriorityArbiter;
use SymfonyProcessManager\Autoscaler\AutoscalerConfig;
use SymfonyProcessManager\Autoscaler\AutoscalerLoop;
use SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Consumer\ConsumerConfig;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\Metrics\MessageClassResolver;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Transport\ConsumeArgs;
use SymfonyProcessManager\Worker\WorkerIpcSubscriber;

final class SymfonyProcessManagerExtension extends Extension
{
    public const PARAM_REQUIRED_STRATEGY_SERVICES = 'symfony_process_manager.required_scaling_strategy_services';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->registerForAutoconfiguration(ScalingStrategyInterface::class)
            ->addTag(ScalingStrategyInterface::TAG);

        $config = $this->processConfiguration(new Configuration(), $configs);

        $shutdownTimeout = $config['shutdown_timeout'];
        $totalCap = $config['total_cap'] ?? null;
        $autoscalerInterval = $config['autoscaler_interval_sec'];
        $messages = $config['metrics']['messages'];

        $poolDefinitions = $this->buildPoolDefinitions($container, $config['consumers']);

        $container->getDefinition(MessageClassResolver::class)
            ->setArgument('$whitelist', $messages['whitelist']);

        $container->getDefinition(ProcessManagerLoop::class)
            ->setArgument('$pools', $poolDefinitions)
            ->setArgument('$messagesMetricsEnabled', $messages['enabled'])
            ->setArgument('$durationBuckets', $messages['duration_buckets'])
            ->setArgument('$shutdownTimeoutSeconds', $shutdownTimeout === 0 ? null : $shutdownTimeout);

        if (!$messages['enabled']) {
            $container->removeDefinition(WorkerIpcSubscriber::class);
        }

        $container->getDefinition(AutoscalerLoop::class)
            ->setArgument('$pools', $poolDefinitions)
            ->setArgument('$arbiter', $totalCap === null ? null : new Reference(PriorityArbiter::class))
            ->setArgument('$intervalSec', $autoscalerInterval);

        if ($totalCap !== null) {
            $container->getDefinition(PriorityArbiter::class)
                ->setArgument('$totalCap', $totalCap);
        } else {
            $container->removeDefinition(PriorityArbiter::class);
        }

        $strategyServiceMap = $this->collectServiceStrategyMap($config['consumers']);
        $strategyLocatorRefs = [];
        foreach (array_keys($strategyServiceMap) as $serviceId) {
            $strategyLocatorRefs[$serviceId] = new Reference($serviceId);
        }
        $container->getDefinition(StrategyRegistry::class)
            ->setArgument('$serviceLocator', new ServiceLocatorArgument($strategyLocatorRefs));

        $container->setParameter(self::PARAM_REQUIRED_STRATEGY_SERVICES, $strategyServiceMap);

        $container->getDefinition(HttpServer::class)
            ->setArgument('$host', $config['http_server']['host'])
            ->setArgument('$port', $config['http_server']['port']);
    }

    /**
     * @param array<string, array{transports: list<string>, processes: ?int, failure_limit: int, failure_window: int, backoff_base: int, backoff_max: int, poll_interval_ms: int, autoscaler?: array{min: int, max: int, priority: int, smoothing_window_sec: int, scale_up_cooldown_sec: int, scale_down_cooldown_sec: int, scale_up_step: int, scale_down_step: int, strategy: array{type: string, target?: ?float, scale_up_threshold?: ?float, scale_down_threshold?: ?float, id?: ?string}}, consume_args: array{memory_limit: ?int, time_limit: ?int, limit: ?int, sleep: ?int, queues: list<string>, extra: list<string>}}> $consumers
     * @return list<Reference>
     */
    private function buildPoolDefinitions(ContainerBuilder $container, array $consumers): array
    {
        $pools = [];
        $startingId = 1;

        foreach ($consumers as $name => $consumer) {
            $consumeArgs = new Definition(ConsumeArgs::class);
            $consumeArgs->setFactory([ConsumeArgs::class, 'create']);
            $consumeArgs->setArguments([
                $consumer['consume_args']['memory_limit'],
                $consumer['consume_args']['time_limit'],
                $consumer['consume_args']['limit'],
                $consumer['consume_args']['sleep'],
                $consumer['consume_args']['queues'],
                $consumer['consume_args']['extra'],
            ]);

            $autoscalerArr = $consumer['autoscaler'] ?? null;
            $processes = $consumer['processes'] ?? 1;

            if ($autoscalerArr !== null) {
                $strategyConfig = $this->buildStrategyConfigDef($autoscalerArr['strategy'], $autoscalerArr['min']);
                $autoscalerDef = new Definition(AutoscalerConfig::class);
                $autoscalerDef->setArguments([
                    $autoscalerArr['min'],
                    $autoscalerArr['max'],
                    $autoscalerArr['priority'],
                    $autoscalerArr['smoothing_window_sec'],
                    $autoscalerArr['scale_up_cooldown_sec'],
                    $autoscalerArr['scale_down_cooldown_sec'],
                    $autoscalerArr['scale_up_step'],
                    $autoscalerArr['scale_down_step'],
                    $strategyConfig,
                ]);
                $initialWorkers = $autoscalerArr['min'];
            } else {
                $autoscalerDef = new Definition(AutoscalerConfig::class);
                $autoscalerDef->setFactory([AutoscalerConfig::class, 'legacyFixed']);
                $autoscalerDef->setArguments([$processes]);
                $initialWorkers = $processes;
            }

            $consumerConfig = new Definition(ConsumerConfig::class);
            $consumerConfig->setArguments([
                $name,
                array_values($consumer['transports']),
                $consumer['failure_limit'],
                $consumer['failure_window'],
                $consumer['backoff_base'],
                $consumer['backoff_max'],
                $consumer['poll_interval_ms'],
                $consumeArgs,
                $autoscalerDef,
            ]);

            $poolId = sprintf('symfony_process_manager.worker_pool.%s', $name);
            $poolDef = new Definition(WorkerPool::class);
            $poolDef->setArguments([$consumerConfig, $startingId]);
            $poolDef->setPublic(false);
            $container->setDefinition($poolId, $poolDef);

            $pools[] = new Reference($poolId);

            $startingId += $initialWorkers;
        }

        return $pools;
    }

    /**
     * @param array{type: string, target?: ?float, scale_up_threshold?: ?float, scale_down_threshold?: ?float, id?: ?string} $strategy
     */
    private function buildStrategyConfigDef(array $strategy, int $minWorkers): Definition
    {
        $type = $strategy['type'];
        $params = [];
        $serviceId = null;

        if ($type === 'utilization') {
            $params['target'] = $strategy['target'] ?? 0.7;
            if (isset($strategy['scale_up_threshold'])) {
                $params['scale_up_threshold'] = $strategy['scale_up_threshold'];
            }
            if (isset($strategy['scale_down_threshold'])) {
                $params['scale_down_threshold'] = $strategy['scale_down_threshold'];
            }
        }

        if ($type === 'service') {
            $serviceId = $strategy['id'] ?? null;
        }

        if ($type === 'fixed') {
            $params['count'] = $minWorkers;
        }

        $def = new Definition(StrategyConfig::class);
        $def->setArguments([$type, $params, $serviceId]);

        return $def;
    }

    /**
     * @param array<string, array{autoscaler?: array{strategy: array{type: string, id?: ?string}}}> $consumers
     * @return array<string, list<string>> Map of service ID to the consumer labels that reference it.
     */
    private function collectServiceStrategyMap(array $consumers): array
    {
        $map = [];
        foreach ($consumers as $consumerName => $consumer) {
            $strategy = $consumer['autoscaler']['strategy'] ?? null;
            if (!is_array($strategy)) {
                continue;
            }
            if ($strategy['type'] !== 'service') {
                continue;
            }
            $serviceId = $strategy['id'] ?? null;
            if ($serviceId === null || $serviceId === '') {
                continue;
            }
            $map[$serviceId][] = $consumerName;
        }

        return $map;
    }

    public function getAlias(): string
    {
        return 'symfony_process_manager';
    }
}
