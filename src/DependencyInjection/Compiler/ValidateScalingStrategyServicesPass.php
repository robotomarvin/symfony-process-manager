<?php

declare(strict_types=1);

namespace SymfonyProcessManager\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface;
use SymfonyProcessManager\DependencyInjection\SymfonyProcessManagerExtension;

final class ValidateScalingStrategyServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, list<string>> $required */
        $required = $container->getParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES);

        foreach ($required as $serviceId => $consumers) {
            $this->validate($container, $serviceId, $consumers);
        }

        $container->getParameterBag()->remove(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES);
    }

    /**
     * @param list<string> $consumers
     */
    private function validate(ContainerBuilder $container, string $serviceId, array $consumers): void
    {
        if (!$container->hasDefinition($serviceId) && !$container->hasAlias($serviceId)) {
            throw new InvalidArgumentException(sprintf(
                'Autoscaler strategy service "%s" referenced by consumer(s) [%s] is not defined.',
                $serviceId,
                implode(', ', $consumers),
            ));
        }

        $definition = $container->findDefinition($serviceId);

        if ($definition->getTag(ScalingStrategyInterface::TAG) !== []) {
            return;
        }

        $class = $definition->getClass();
        if ($class !== null) {
            $class = $container->getParameterBag()->resolveValue($class);
        }

        if (is_string($class) && is_a($class, ScalingStrategyInterface::class, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Autoscaler strategy service "%s" referenced by consumer(s) [%s] must implement %s. '
            . 'Implement the interface (autoconfigure tags it automatically) or tag the service with "%s" manually.',
            $serviceId,
            implode(', ', $consumers),
            ScalingStrategyInterface::class,
            ScalingStrategyInterface::TAG,
        ));
    }
}
