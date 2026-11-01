<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bus that records the dispatched envelopes.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope           = Envelope::wrap($message, $stamps);
        $this->dispatched[] = $envelope;

        return $envelope;
    }
}
