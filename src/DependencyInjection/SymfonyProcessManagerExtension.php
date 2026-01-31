<?php

declare(strict_types=1);

namespace SymfonyProcessManager\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use SymfonyProcessManager\Command\ServeCommand;

final class SymfonyProcessManagerExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->getDefinition(ServeCommand::class)
            ->setArgument('$transportConfigs', $config['transports'])
            ->setArgument('$httpHost', $config['http_server']['host'])
            ->setArgument('$httpPort', $config['http_server']['port']);
    }
}
