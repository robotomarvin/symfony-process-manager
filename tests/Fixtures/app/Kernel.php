<?php

namespace SymfonyProcessManager\Tests\Fixtures\App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        $bundles = require $this->getProjectDir() . '/config/bundles.php';

        foreach ($bundles as $class => $envs) {
            if (($envs[$this->environment] ?? $envs['all'] ?? false) === true) {
                yield new $class();
            }
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import($this->getProjectDir() . '/config/packages/*.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
