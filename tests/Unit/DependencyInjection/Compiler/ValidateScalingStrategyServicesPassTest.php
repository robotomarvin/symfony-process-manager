<?php

declare(strict_types=1);

namespace SymfonyProcessManager\Tests\Unit\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use SymfonyProcessManager\Autoscaler\PoolSnapshot;
use SymfonyProcessManager\Autoscaler\Strategy\ScalingStrategyInterface;
use SymfonyProcessManager\DependencyInjection\Compiler\ValidateScalingStrategyServicesPass;
use SymfonyProcessManager\DependencyInjection\SymfonyProcessManagerExtension;

#[CoversClass(ValidateScalingStrategyServicesPass::class)]
final class ValidateScalingStrategyServicesPassTest extends TestCase
{
    public function testNoOpWhenNoServiceStrategiesConfigured(): void
    {
        $container = $this->buildContainer([]);

        (new ValidateScalingStrategyServicesPass())->process($container);

        self::assertFalse($container->hasParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES));
    }

    public function testAcceptsTaggedService(): void
    {
        $container = $this->buildContainer(['app.strategy' => ['async']]);
        $container->register('app.strategy', ValidScalingStrategyStub::class)
            ->addTag(ScalingStrategyInterface::TAG);

        (new ValidateScalingStrategyServicesPass())->process($container);

        self::assertFalse($container->hasParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES));
    }

    public function testAcceptsUntaggedServiceWhoseClassImplementsInterface(): void
    {
        $container = $this->buildContainer(['app.strategy' => ['async']]);
        $container->register('app.strategy', ValidScalingStrategyStub::class);

        (new ValidateScalingStrategyServicesPass())->process($container);

        self::assertFalse($container->hasParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES));
    }

    public function testRejectsUndefinedService(): void
    {
        $container = $this->buildContainer(['app.missing' => ['async', 'priority']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('app.missing');
        $this->expectExceptionMessage('consumer(s) [async, priority]');

        (new ValidateScalingStrategyServicesPass())->process($container);
    }

    public function testRejectsServiceThatDoesNotImplementInterface(): void
    {
        $container = $this->buildContainer(['app.bad' => ['async']]);
        $container->register('app.bad', \stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ScalingStrategyInterface::class);
        $this->expectExceptionMessage(ScalingStrategyInterface::TAG);
        $this->expectExceptionMessage('consumer(s) [async]');

        (new ValidateScalingStrategyServicesPass())->process($container);
    }

    public function testFollowsAliases(): void
    {
        $container = $this->buildContainer(['app.alias' => ['async']]);
        $container->register('app.real', ValidScalingStrategyStub::class)
            ->addTag(ScalingStrategyInterface::TAG);
        $container->setAlias('app.alias', 'app.real');

        (new ValidateScalingStrategyServicesPass())->process($container);

        self::assertFalse($container->hasParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES));
    }

    public function testResolvesParameterizedClassName(): void
    {
        $container = $this->buildContainer(['app.strategy' => ['async']]);
        $container->setParameter('app.strategy.class', ValidScalingStrategyStub::class);
        $container->register('app.strategy', '%app.strategy.class%');

        (new ValidateScalingStrategyServicesPass())->process($container);

        self::assertFalse($container->hasParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES));
    }

    public function testRejectsServiceWithUnknownClass(): void
    {
        $container = $this->buildContainer(['app.factory' => ['async']]);
        $definition = new Definition();
        $definition->setFactory(['SomeFactory', 'create']);
        $container->setDefinition('app.factory', $definition);

        $this->expectException(InvalidArgumentException::class);

        (new ValidateScalingStrategyServicesPass())->process($container);
    }

    /**
     * @param array<string, list<string>> $required
     */
    private function buildContainer(array $required): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(SymfonyProcessManagerExtension::PARAM_REQUIRED_STRATEGY_SERVICES, $required);

        return $container;
    }
}

final class ValidScalingStrategyStub implements ScalingStrategyInterface
{
    public function decide(PoolSnapshot $snapshot): int
    {
        return 1;
    }
}
