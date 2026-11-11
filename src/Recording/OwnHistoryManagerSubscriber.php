<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the bundle's OWN entity manager (`doctrine.entity_manager`) usable in a kept-alive kernel (1.1).
 *
 * Registered only when `doctrine.entity_manager` is set: that manager maps the bundle history entity only, so the bundle
 * may recover it (reset when a failed flush closed it) at the start of the next request or message, and clear it at
 * the end so no managed history row survives into the next request. The host's managers are never reset nor cleared.
 *
 * @internal
 */
final readonly class OwnHistoryManagerSubscriber
{
    /**
     * @param string $managerName the configured `doctrine.entity_manager`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $managerName,
    ) {
    }

    /**
     * Recovers the manager at the start of a main request.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->recover();
        }
    }

    /**
     * Resets the bundle manager when a failed write closed it (the lazy manager is re-created in place).
     */
    #[AsEventListener(event: 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', priority: 4096)]
    public function recover(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager($this->managerName);
        }
    }

    /**
     * Detaches every managed bundle entity at the end of the request, message or command.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -1024)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -1024)]
    #[AsEventListener(event: 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', priority: -1024)]
    #[AsEventListener(event: 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', priority: -1024)]
    public function clear(): void
    {
        $manager = $this->registry->getManager($this->managerName);
        if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
            // @igor-ignore - This IS the request-boundary reset of the bundle's own manager (no state kept).
            $manager->clear();
        }
    }
}
