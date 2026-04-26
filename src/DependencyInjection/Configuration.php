<?php

declare(strict_types=1);

namespace SymfonyProcessManager\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('symfony_process_manager');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->integerNode('shutdown_timeout')
                    ->defaultValue(30)
                    ->min(0)
                    ->info('Seconds to wait after SIGTERM before escalating to SIGKILL. 0 = wait indefinitely.')
                ->end()
                ->integerNode('total_cap')
                    ->defaultNull()
                    ->min(1)
                    ->info('Optional global ceiling on the sum of workers across all pools.')
                ->end()
                ->integerNode('autoscaler_interval_sec')
                    ->defaultValue(10)
                    ->min(1)
                    ->info('How often the autoscaler evaluates strategies (seconds).')
                ->end()
                ->arrayNode('http_server')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('host')->defaultValue('127.0.0.1')->end()
                        ->integerNode('port')->defaultValue(9100)->min(0)->end()
                    ->end()
                ->end()
                ->arrayNode('transports')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->integerNode('processes')->defaultNull()->min(1)->end()
                            ->integerNode('failure_limit')->defaultValue(3)->min(1)->end()
                            ->integerNode('failure_window')->defaultValue(60)->min(1)->end()
                            ->integerNode('backoff_base')->defaultValue(1)->min(1)->end()
                            ->integerNode('backoff_max')->defaultValue(30)->min(1)->end()
                            ->integerNode('poll_interval_ms')->defaultValue(200)->min(1)->end()
                            ->arrayNode('autoscaler')
                                ->children()
                                    ->integerNode('min')->isRequired()->min(1)->end()
                                    ->integerNode('max')->isRequired()->min(1)->end()
                                    ->integerNode('priority')->defaultValue(0)->end()
                                    ->integerNode('smoothing_window_sec')->defaultValue(30)->min(1)->end()
                                    ->integerNode('scale_up_cooldown_sec')->defaultValue(30)->min(0)->end()
                                    ->integerNode('scale_down_cooldown_sec')->defaultValue(300)->min(0)->end()
                                    ->integerNode('scale_up_step')->defaultValue(2)->min(1)->end()
                                    ->integerNode('scale_down_step')->defaultValue(1)->min(1)->end()
                                    ->arrayNode('strategy')
                                        ->isRequired()
                                        ->children()
                                            ->scalarNode('type')->isRequired()->end()
                                            ->floatNode('target')->defaultNull()->end()
                                            ->scalarNode('id')->defaultNull()->end()
                                        ->end()
                                        ->validate()
                                            ->ifTrue(static function (array $v): bool {
                                                $type = $v['type'] ?? null;
                                                return !in_array($type, ['fixed', 'utilization', 'service'], true);
                                            })
                                            ->thenInvalid('Strategy type must be one of: fixed, utilization, service.')
                                        ->end()
                                        ->validate()
                                            ->ifTrue(static function (array $v): bool {
                                                return ($v['type'] ?? null) === 'service' && empty($v['id']);
                                            })
                                            ->thenInvalid('Strategy type "service" requires an "id".')
                                        ->end()
                                    ->end()
                                ->end()
                                ->validate()
                                    ->ifTrue(static function (array $v): bool {
                                        return isset($v['min'], $v['max']) && $v['min'] > $v['max'];
                                    })
                                    ->thenInvalid('autoscaler.min must be <= autoscaler.max.')
                                ->end()
                            ->end()
                            ->arrayNode('consume_args')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->integerNode('memory_limit')->defaultNull()->min(1)->end()
                                    ->integerNode('time_limit')->defaultNull()->min(1)->end()
                                    ->integerNode('limit')->defaultNull()->min(1)->end()
                                    ->integerNode('sleep')->defaultNull()->min(0)->end()
                                    ->arrayNode('queues')
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                    ->arrayNode('extra')
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static function (array $v): bool {
                                return isset($v['autoscaler']) && $v['processes'] !== null;
                            })
                            ->thenInvalid('Transport configuration cannot set both "processes" and "autoscaler".')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static function (array $v): bool {
                    if ($v['total_cap'] === null) {
                        return false;
                    }

                    $sum = 0;
                    foreach ($v['transports'] as $transport) {
                        if (isset($transport['autoscaler'])) {
                            $sum += $transport['autoscaler']['min'];
                            continue;
                        }
                        $sum += $transport['processes'] ?? 1;
                    }

                    return $sum > $v['total_cap'];
                })
                ->thenInvalid('total_cap is too low to satisfy the sum of pool minimums (autoscaled minima plus fixed processes).')
            ->end()
        ;

        return $treeBuilder;
    }
}
