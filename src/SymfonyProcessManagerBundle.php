<?php

declare(strict_types=1);

namespace SymfonyProcessManager;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use SymfonyProcessManager\DependencyInjection\Compiler\ValidateScalingStrategyServicesPass;

final class SymfonyProcessManagerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ValidateScalingStrategyServicesPass());
    }
}
