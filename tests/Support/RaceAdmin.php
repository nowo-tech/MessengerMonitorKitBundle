<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Connection as DbalConnection;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as MessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;

/**
 * One admin: a DBAL connection, its Doctrine `failed` / `async` transports, a lock store on the same connection and a
 * bus that sends to `async` (running the other admin's action first when asked).
 */
final class RaceAdmin implements MessageBusInterface
{
    public readonly DoctrineTransport $failed;

    public readonly DoctrineTransport $async;

    private ?Closure $before = null;

    public function __construct(
        public readonly DbalConnection $connection,
        private readonly bool $withLock,
    ) {
        $this->failed = new DoctrineTransport(new MessengerConnection(['table_name' => 'messenger_messages', 'queue_name' => 'failed', 'auto_setup' => false], $connection), new PhpSerializer());
        $this->async  = new DoctrineTransport(new MessengerConnection(['table_name' => 'messenger_messages', 'queue_name' => 'async', 'auto_setup' => false], $connection), new PhpSerializer());
    }

    public function retrier(): FailedMessageRetrier
    {
        return new FailedMessageRetrier(
            Monitor::transports(['failed' => $this->failed, 'async' => $this->async]),
            $this,
            ['failed'],
            lockFactory: $this->withLock ? new LockFactory(new DoctrineDbalStore($this->connection, gcProbability: 0.0)) : null,
        );
    }

    public function failedInfo(): TransportInfo
    {
        return Monitor::transports(['failed' => $this->failed, 'async' => $this->async])->get('failed');
    }

    /** Parks one failed message and returns its id. */
    public function seed(): string
    {
        $sent = $this->failed->send(new Envelope(new DemoMessage(), [new BusNameStamp('messenger.bus.default'), new SentToFailureTransportStamp('async'), new RedeliveryStamp(1)]));

        return (string) $sent->last(TransportMessageIdStamp::class)?->getId();
    }

    /** Runs `$action` once, inside the next dispatch (between A's lookup and A's reject). */
    public function beforeDispatch(Closure $action): void
    {
        $this->before = $action;
    }

    /** Holds a row lock on the parked message until the caller rolls back. */
    public function lockRow(string $id): void
    {
        $this->connection->beginTransaction();
        $this->connection->fetchOne('SELECT id FROM messenger_messages WHERE id = ? FOR UPDATE', [$id]);
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $before       = $this->before;
        $this->before = null;
        if ($before instanceof Closure) {
            $before();
        }

        $envelope = Envelope::wrap($message, $stamps);
        TestCase::assertSame(['async'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());

        return $this->async->send($envelope);
    }
}
