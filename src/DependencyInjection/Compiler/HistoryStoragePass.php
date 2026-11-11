<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MessengerMonitorKitBundle\Command\PurgeHistoryCommand;
use Nowo\MessengerMonitorKitBundle\Controller\SettingsController;
use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\Recording\DbalHistoryWriter;
use Nowo\MessengerMonitorKitBundle\Recording\ToggleableMonitorStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Zenstruck\Messenger\Monitor\History\Storage\ORMStorage;

use function class_exists;
use function is_string;

/**
 * Wires the bundle onto zenstruck's ORM history storage: decorates `zenstruck_messenger_monitor.history.storage` with
 * {@see ToggleableMonitorStorage} (recording switch, optional classes, results minimisation) and hands the configured
 * history entity class to the batched purger.
 *
 * Without a history storage (no `zenstruck_messenger_monitor.storage.orm.entity_class` and `history.bundle_entity:
 * false`) the admin UI refuses to compile; with the UI off the history services are removed.
 */
final class HistoryStoragePass implements CompilerPassInterface
{
    /** zenstruck's history storage service. */
    public const STORAGE_ID = 'zenstruck_messenger_monitor.history.storage';

    /** Services that need the history storage. */
    public const HISTORY_SERVICES = [ToggleableMonitorStorage::class, HistoryPurger::class, AutoPurger::class, PurgeHistoryCommand::class];

    /**
     * Decorates the storage, or removes / refuses what needs it.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(HistoryPurger::class)) {
            return;
        }

        $entityClass = $container->hasDefinition(self::STORAGE_ID) ? $container->getDefinition(self::STORAGE_ID)->getArgument(1) : null;
        if (!is_string($entityClass) || !class_exists($entityClass)) {
            if ($container->hasDefinition(SettingsController::class)) {
                throw new LogicException('NowoMessengerMonitorKitBundle: the admin UI needs zenstruck\'s ORM history storage. Keep nowo_messenger_monitor_kit.history.bundle_entity: true, set zenstruck_messenger_monitor.storage.orm.entity_class, or set nowo_messenger_monitor_kit.web_ui.enabled: false.');
            }
            foreach (self::HISTORY_SERVICES as $id) {
                $container->removeDefinition($id);
            }

            return;
        }

        $container->getDefinition(HistoryPurger::class)->replaceArgument(1, $entityClass);
        $storage = $container->getDefinition(ToggleableMonitorStorage::class)->setDecoratedService(self::STORAGE_ID);

        // 1.1 (rule 6): zenstruck's ORM storage flushes the manager of the history entity (the host's by default)
        // after every handled / failed message; the bundle writes those rows with DBAL instead.
        if ($container->getDefinition(self::STORAGE_ID)->getClass() === ORMStorage::class) {
            // @igor-ignore - Compile-time container definition (not request state).
            $storage->setArgument(5, new Definition(DbalHistoryWriter::class, [new Reference('doctrine'), $entityClass]));
        }
    }
}
