<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transport that is neither countable nor listable (Symfony 8.2's in-memory transport is both, so it cannot stand in).
 */
final class UncountableTransport implements TransportInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
