<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\HistoryEntityManagerPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\NowoMessengerMonitorKitExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(HistoryEntityManagerPass::class)]
final class HistoryEntityManagerPassTest extends TestCase
{
    #[Test]
    public function doesNothingWithoutADedicatedManager(): void
    {
        $container = new ContainerBuilder();
        (new HistoryEntityManagerPass())->process($container);
        $container->setParameter(NowoMessengerMonitorKitExtension::ENTITY_MANAGER_PARAMETER, null);
        (new HistoryEntityManagerPass())->process($container);
        self::assertFalse($container->hasParameter('doctrine.entity_managers'));
    }

    #[Test]
    public function removesTheBundleMappingFromEveryOtherManager(): void
    {
        $container = $this->container(['default' => 'x', 'monitor' => 'y', 'reporting' => 'z']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));
        $container->setDefinition('doctrine.orm.monitor_metadata_driver', $this->chain(true, false));

        (new HistoryEntityManagerPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'host mapping kept');
        self::assertCount(1, $container->getDefinition('doctrine.orm.monitor_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function unknownManagerFailsEvenInCheckOnlyMode(): void
    {
        $container = $this->container(['default' => 'x']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('known: "default"');
        (new HistoryEntityManagerPass(checkOnly: true))->process($container);
    }

    #[Test]
    public function checkOnlyModeLeavesTheMappingAlone(): void
    {
        $container = $this->container(['default' => 'x', 'monitor' => 'y']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));
        (new HistoryEntityManagerPass(checkOnly: true))->process($container);
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function checkOnlyModePointsZenstrucksSuperclassMappingAtTheManager(): void
    {
        $container = $this->container(['default' => 'x', 'monitor' => 'y']);
        $container->setParameter(HistoryEntityManagerPass::ZENSTRUCK_MANAGER_PARAMETER, 'default');
        (new HistoryEntityManagerPass(checkOnly: true))->process($container);
        self::assertSame('monitor', $container->getParameter(HistoryEntityManagerPass::ZENSTRUCK_MANAGER_PARAMETER));
    }

    #[Test]
    public function managerWithoutTheBundleMappingFails(): void
    {
        $container = $this->container(['default' => 'x', 'monitor' => 'y']);
        $container->setDefinition('doctrine.orm.monitor_metadata_driver', $this->chain(false, true));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');
        (new HistoryEntityManagerPass())->process($container);
    }

    /**
     * @param array<string, string> $managers
     */
    private function container(array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoMessengerMonitorKitExtension::ENTITY_MANAGER_PARAMETER, 'monitor');
        $container->setParameter('doctrine.entity_managers', $managers);

        return $container;
    }

    private function chain(bool $bundle, bool $host): Definition
    {
        $chain = new Definition();
        if ($bundle) {
            $chain->addMethodCall('addDriver', [new Definition(), HistoryEntityManagerPass::ENTITY_NAMESPACE]);
        }
        if ($host) {
            $chain->addMethodCall('addDriver', [new Definition(), 'App\\Entity']);
        }

        return $chain;
    }
}
