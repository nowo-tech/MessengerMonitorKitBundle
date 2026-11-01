<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_slice;
use function array_values;
use function count;

/**
 * Listable, countable in-memory transport (ids 1, 2, …); `failCount` makes getMessageCount() throw.
 */
final class MemoryTransport implements TransportInterface, ListableReceiverInterface, MessageCountAwareInterface
{
    /** @var array<int|string, Envelope> */
    public array $envelopes = [];

    /** @var list<string> */
    public array $rejected = [];

    public bool $failCount = false;

    public bool $failReject = false;

    private int $next = 1;

    public function get(): iterable
    {
        return array_values($this->envelopes);
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
        if ($this->failReject) {
            throw new RuntimeException('reject failed');
        }
        $id = (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
        unset($this->envelopes[$id]);
        $this->rejected[] = $id;
    }

    public function send(Envelope $envelope): Envelope
    {
        $id                   = (string) $this->next++;
        $envelope             = $envelope->with(new TransportMessageIdStamp($id));
        $this->envelopes[$id] = $envelope;

        return $envelope;
    }

    public function all(?int $limit = null): iterable
    {
        return array_slice(array_values($this->envelopes), 0, $limit);
    }

    public function find(mixed $id): ?Envelope
    {
        return $this->envelopes[(string) $id] ?? null;
    }

    public function getMessageCount(): int
    {
        if ($this->failCount) {
            throw new RuntimeException('broker down');
        }

        return count($this->envelopes);
    }
}
