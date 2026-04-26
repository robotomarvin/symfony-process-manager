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
use SymfonyProcessManager\Autoscaler\Strategy\StrategyConfig;
use SymfonyProcessManager\Autoscaler\Strategy\StrategyRegistry;
use SymfonyProcessManager\Http\HttpServer;
use SymfonyProcessManager\ProcessManager\ProcessManagerLoop;
use SymfonyProcessManager\ProcessManager\WorkerPool;
use SymfonyProcessManager\Transport\ConsumeArgs;
use SymfonyProcessManager\Transport\TransportConfig;

final class SymfonyProcessManagerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $shutdownTimeout = $config['shutdown_timeout'];
        $totalCap = $config['total_cap'] ?? null;
        $autoscalerInterval = $config['autoscaler_interval_sec'];

        $poolDefinitions = $this->buildPoolDefinitions($container, $config['transports']);

        $container->getDefinition(ProcessManagerLoop::class)
            ->setArgument('$pools', $poolDefinitions)
            ->setArgument('$shutdownTimeoutSeconds', $shutdownTimeout === 0 ? null : $shutdownTimeout);

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

        $serviceStrategyIds = $this->collectServiceStrategyIds($config['transports']);
        $strategyLocatorRefs = [];
        foreach ($serviceStrategyIds as $serviceId) {
            $strategyLocatorRefs[$serviceId] = new Reference($serviceId);
        }
        $container->getDefinition(StrategyRegistry::class)
            ->setArgument('$serviceLocator', new ServiceLocatorArgument($strategyLocatorRefs));

        $container->getDefinition(HttpServer::class)
            ->setArgument('$host', $config['http_server']['host'])
            ->setArgument('$port', $config['http_server']['port']);
    }

    /**
     * @param array<string, array{processes: ?int, failure_limit: int, failure_window: int, backoff_base: int, backoff_max: int, poll_interval_ms: int, autoscaler?: array{min: int, max: int, priority: int, smoothing_window_sec: int, scale_up_cooldown_sec: int, scale_down_cooldown_sec: int, scale_up_step: int, scale_down_step: int, strategy: array{type: string, target?: ?float, scale_up_threshold?: ?float, scale_down_threshold?: ?float, id?: ?string}}, consume_args: array{memory_limit: ?int, time_limit: ?int, limit: ?int, sleep: ?int, queues: list<string>, extra: list<string>}}> $transports
     * @return list<Reference>
     */
    private function buildPoolDefinitions(ContainerBuilder $container, array $transports): array
    {
        $pools = [];
        $startingId = 1;

        foreach ($transports as $name => $transport) {
            $consumeArgs = new Definition(ConsumeArgs::class);
            $consumeArgs->setFactory([ConsumeArgs::class, 'create']);
            $consumeArgs->setArguments([
                $transport['consume_args']['memory_limit'],
                $transport['consume_args']['time_limit'],
                $transport['consume_args']['limit'],
                $transport['consume_args']['sleep'],
                $transport['consume_args']['queues'],
                $transport['consume_args']['extra'],
            ]);

            $autoscalerArr = $transport['autoscaler'] ?? null;
            $processes = $transport['processes'] ?? 1;

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

            $transportConfig = new Definition(TransportConfig::class);
            $transportConfig->setArguments([
                $name,
                $transport['failure_limit'],
                $transport['failure_window'],
                $transport['backoff_base'],
                $transport['backoff_max'],
                $transport['poll_interval_ms'],
                $consumeArgs,
                $autoscalerDef,
            ]);

            $poolId = sprintf('symfony_process_manager.worker_pool.%s', $name);
            $poolDef = new Definition(WorkerPool::class);
            $poolDef->setArguments([$transportConfig, $startingId]);
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
     * @param array<string, array{autoscaler?: array{strategy: array{type: string, id?: ?string}}}> $transports
     * @return list<string>
     */
    private function collectServiceStrategyIds(array $transports): array
    {
        $ids = [];
        foreach ($transports as $transport) {
            $strategy = $transport['autoscaler']['strategy'] ?? null;
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
            $ids[] = $serviceId;
        }

        return array_values(array_unique($ids));
    }

    public function getAlias(): string
    {
        return 'symfony_process_manager';
    }
}
