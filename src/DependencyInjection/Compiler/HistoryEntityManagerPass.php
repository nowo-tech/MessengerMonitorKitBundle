<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler;

use Nowo\MessengerMonitorKitBundle\DependencyInjection\NowoMessengerMonitorKitExtension;
use Nowo\MessengerMonitorKitBundle\Recording\OwnHistoryManagerSubscriber;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Keeps the bundle history entity on `doctrine.entity_manager` only (1.1).
 *
 * DoctrineBundle's `auto_mapping` (the Flex default on the default manager) maps every bundle's `Entity` directory,
 * so with a dedicated manager configured the entities would be mapped twice and `getManagerForClass()` would return
 * the default manager. This pass removes the bundle namespace from the mapping driver chain of every other manager
 * and fails the build when the configured manager does not exist or does not map the entities.
 */
final readonly class HistoryEntityManagerPass implements CompilerPassInterface
{
    /** Manager of zenstruck's XML mapping of the history superclass. */
    public const ZENSTRUCK_MANAGER_PARAMETER = 'zenstruck_messenger_monitor.history.orm_manager';

    public const ENTITY_NAMESPACE = 'Nowo\\MessengerMonitorKitBundle\\Entity';

    /**
     * @param bool $checkOnly Only fail fast on an unknown manager (registered before the mapping passes, which would
     *                        otherwise fail first with a less helpful "non-existent service" error)
     */
    public function __construct(
        private bool $checkOnly = false,
    ) {
    }

    /**
     * Validates the configured manager and keeps the history entity mapped on it only.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoMessengerMonitorKitExtension::ENTITY_MANAGER_PARAMETER)) {
            return;
        }

        $name = $container->getParameter(NowoMessengerMonitorKitExtension::ENTITY_MANAGER_PARAMETER);
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_messenger_monitor_kit.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        if ($this->checkOnly) {
            // Before the mapping passes: zenstruck maps its ProcessedMessage superclass on this manager too.
            if ($container->hasParameter(self::ZENSTRUCK_MANAGER_PARAMETER)) {
                $container->setParameter(self::ZENSTRUCK_MANAGER_PARAMETER, $name);
            }

            return;
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $isBundleDriver = $call[0] === 'addDriver' && is_string($call[1][1] ?? null) && str_starts_with($call[1][1], self::ENTITY_NAMESPACE);
                if ($isBundleDriver && $manager !== $name) {
                    continue;
                }

                $maps    = $maps || $isBundleDriver;
                $calls[] = $call;
            }

            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_messenger_monitor_kit.doctrine.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }

        // LH-MMK-M1: only a dedicated manager is the bundle's own (cleared at every boundary, reset when closed); the
        // host's default manager is never treated as such, even when the option names it.
        if ($container->hasParameter('doctrine.default_entity_manager') && $container->getParameter('doctrine.default_entity_manager') === $name) {
            $container->removeDefinition(OwnHistoryManagerSubscriber::class);
        }
    }
}
