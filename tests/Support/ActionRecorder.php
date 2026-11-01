<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records the audit events of the admin UI.
 */
final class ActionRecorder
{
    /** @var list<MessengerMonitorActionEvent> */
    public array $events = [];

    #[AsEventListener]
    public function __invoke(MessengerMonitorActionEvent $event): void
    {
        $this->events[] = $event;
    }
}
