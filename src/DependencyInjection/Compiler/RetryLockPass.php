<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler;

use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

use function is_string;
use function sprintf;

/**
 * Since 1.3.0 (REQ-RUNTIME-003 rules 4 and 7): the {@see FailedMessageRetrier} always has a lock factory, so two
 * admins (or a double submit) never retry the same failed message twice, nor retry one that another admin removes.
 *
 * - `retry.lock_factory` names an existing service (default `lock.factory`, registered by framework.lock, which is on
 *   by default now that symfony/lock is required): that factory;
 * - the default id does not exist (framework.lock: false): a bundle-owned factory on a local flock store, which
 *   serialises the processes and worker threads of one host only (documented: several servers need a shared store);
 * - another id that does not exist: a configuration error.
 */
final class RetryLockPass implements CompilerPassInterface
{
    /** Parameter set by the extension: `retry.lock_factory`. */
    public const PARAMETER = 'nowo_messenger_monitor_kit.retry.lock_factory';

    /** Service (alias or definition) the retrier receives. */
    public const SERVICE = 'nowo_messenger_monitor_kit.retry_lock_factory';

    /** Default of `retry.lock_factory`. */
    public const DEFAULT_FACTORY = 'lock.factory';

    /**
     * Points {@see self::SERVICE} at the configured factory, or at a local flock factory.
     */
    public function process(ContainerBuilder $container): void
    {
        $id = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : self::DEFAULT_FACTORY;
        $id = is_string($id) ? $id : self::DEFAULT_FACTORY;

        if ($container->has($id)) {
            $container->setAlias(self::SERVICE, $id);

            return;
        }

        if ($id !== self::DEFAULT_FACTORY) {
            throw new InvalidArgumentException(sprintf('NowoMessengerMonitorKitBundle: retry.lock_factory "%s" is not a service.', $id));
        }

        $container->setDefinition(self::SERVICE, new Definition(LockFactory::class, [new Definition(FlockStore::class)]));
    }
}
