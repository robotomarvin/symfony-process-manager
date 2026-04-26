<?php

declare(strict_types=1);

namespace SymfonyProcessManager\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('symfony_process_manager');

        $treeBuilder->getRootNode()
            ->children()
                ->integerNode('shutdown_timeout')
                    ->defaultValue(30)
                    ->min(0)
                    ->info('Seconds to wait after SIGTERM before escalating to SIGKILL. 0 = wait indefinitely.')
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
                            ->integerNode('processes')->defaultValue(1)->min(1)->end()
                            ->integerNode('failure_limit')->defaultValue(3)->min(1)->end()
                            ->integerNode('failure_window')->defaultValue(60)->min(1)->end()
                            ->integerNode('backoff_base')->defaultValue(1)->min(1)->end()
                            ->integerNode('backoff_max')->defaultValue(30)->min(1)->end()
                            ->integerNode('poll_interval_ms')->defaultValue(200)->min(1)->end()
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
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
