<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Retry;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Throwable;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;
use Zenstruck\Messenger\Monitor\Transport\QueuedMessage;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;
use Zenstruck\Messenger\Monitor\Transports;

use function in_array;
use function is_scalar;
use function sha1;
use function str_contains;

/**
 * Retries and discards (removes) messages parked on a failure transport.
 *
 * A retry sends the envelope back to the transport it failed on (`SentToFailureTransportStamp` origin) — never to the
 * failure transport, never inline in the admin request — through the routable bus (its `BusNameStamp` picks the bus),
 * then rejects it from the failure transport. The failure stamp, received stamp, transport names and old transport
 * id are removed (Symfony drops a re-failing message that still carries the failure stamp of the same receiver, and a
 * received stamp would handle it synchronously); handled stamps and the redelivery counter are kept, so handlers that
 * already succeeded do not run again and the transport retry policy is not restarted.
 *
 * Idempotency: with symfony/lock a retry / removal locks `transport + id`, then looks the message up again inside the
 * lock, so two admins (or a double submit) cannot dispatch it twice. Without a lock factory the second request finds
 * nothing once the first one rejected it; the remaining race window is documented.
 */
final readonly class FailedMessageRetrier
{
    /** Tags added to a retried envelope (shown in the history). */
    public const TAGS = ['retry', 'manual'];

    /**
     * @param list<string> $failureTransports configured failure transport names (empty = names containing "fail")
     * @param string|null $fallbackTransport target when the original transport is gone / not a queue
     * @param list<string> $duplicateRiskClasses message classes whose retry asks for a stronger confirmation
     * @param float $lockTtl seconds a retry / removal of one message is locked
     */
    public function __construct(
        private Transports $transports,
        private MessageBusInterface $bus,
        private array $failureTransports = [],
        private ?string $fallbackTransport = null,
        private array $duplicateRiskClasses = [],
        private ?LockFactory $lockFactory = null,
        private float $lockTtl = 30.0,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether `$name` is a failure transport.
     */
    public function isFailureTransport(string $name): bool
    {
        if ($this->failureTransports !== []) {
            return in_array($name, $this->failureTransports, true);
        }

        return str_contains($name, 'fail');
    }

    /**
     * The listable failure transport named `$name`, or null (not a failure transport, unknown, not listable).
     */
    public function failureTransport(string $name): ?TransportInfo
    {
        if (!$this->isFailureTransport($name) || !in_array($name, $this->transports->names(), true)) {
            return null;
        }
        $transport = $this->transports->get($name);

        return $transport->isListable() ? $transport : null;
    }

    /**
     * Queue transports a message can be sent back to (sync, scheduler and failure transports excluded).
     *
     * @return list<string>
     */
    public function queueTransports(): array
    {
        $names = [];
        foreach ($this->transports->filter()->excludeSync()->excludeSchedules()->names() as $name) {
            if (!$this->isFailureTransport($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Transport a failed message would be sent back to, or null (left parked).
     */
    public function targetFor(QueuedMessage $queued): ?string
    {
        $original = $queued->envelope()->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName();
        if ($original === null) {
            return null;
        }
        $queues = $this->queueTransports();
        if (in_array($original, $queues, true)) {
            return $original;
        }

        return $this->fallbackTransport !== null && in_array($this->fallbackTransport, $queues, true) ? $this->fallbackTransport : null;
    }

    /**
     * Whether retrying the message may repeat side effects (configured `retry.duplicate_risk_classes`).
     */
    public function isDuplicateRisk(QueuedMessage $queued): bool
    {
        $message = $queued->envelope()->getMessage();
        foreach ($this->duplicateRiskClasses as $class) {
            if ($message instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends one failed message back to its original transport.
     */
    public function retry(TransportInfo $failed, string $id): RetryOutcome
    {
        return $this->locked($failed, $id, function (QueuedMessage $queued) use ($failed): RetryOutcome {
            $type   = $queued->message()->shortName();
            $target = $this->targetFor($queued);
            if ($target === null) {
                return new RetryOutcome(RetryStatus::NotRetryable, $type);
            }

            $envelope = $queued->envelope();
            $stamps   = [new TransportNamesStamp([$target])];
            foreach (self::TAGS as $tag) {
                $stamps[] = new TagStamp($tag);
            }
            $this->bus->dispatch(self::cleanEnvelope($envelope), $stamps);
            $failed->get()->reject($envelope);

            $this->logger?->info('Messenger monitor: failed message retried.', ['bundle' => 'messenger_monitor_kit', 'transport' => $failed->name(), 'id' => $queued->id(), 'type' => $type, 'target' => $target]);

            return new RetryOutcome(RetryStatus::Done, $type, $target);
        });
    }

    /**
     * Rejects (deletes) one failed message.
     */
    public function discard(TransportInfo $failed, string $id): RetryOutcome
    {
        return $this->locked($failed, $id, function (QueuedMessage $queued) use ($failed): RetryOutcome {
            $type = $queued->message()->shortName();
            $failed->get()->reject($queued->envelope());
            $this->logger?->info('Messenger monitor: failed message removed.', ['bundle' => 'messenger_monitor_kit', 'transport' => $failed->name(), 'id' => $queued->id(), 'type' => $type]);

            return new RetryOutcome(RetryStatus::Done, $type);
        });
    }

    /**
     * Retries the first `$limit` failed messages.
     */
    public function retryAll(TransportInfo $failed, int $limit): BatchResult
    {
        $result = new BatchResult();
        foreach ($this->ids($failed, $limit) as $id) {
            $result = $result->with($this->retry($failed, $id));
        }

        return $result;
    }

    /**
     * Removes the first `$limit` failed messages.
     */
    public function discardAll(TransportInfo $failed, int $limit): BatchResult
    {
        $result = new BatchResult();
        foreach ($this->ids($failed, $limit) as $id) {
            $result = $result->with($this->discard($failed, $id));
        }

        return $result;
    }

    /**
     * Envelope as it is sent back: without failure / received / transport stamps.
     */
    public static function cleanEnvelope(Envelope $envelope): Envelope
    {
        return $envelope
            ->withoutAll(SentToFailureTransportStamp::class)
            ->withoutAll(ReceivedStamp::class)
            ->withoutAll(TransportNamesStamp::class)
            ->withoutAll(TransportMessageIdStamp::class);
    }

    /**
     * @return list<string>
     */
    private function ids(TransportInfo $failed, int $limit): array
    {
        $ids = [];
        foreach ($failed->list($limit) as $queued) {
            $id = $queued->id();
            if (is_scalar($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Runs `$action` on the message found again inside the per-message lock.
     *
     * @param callable(QueuedMessage): RetryOutcome $action
     */
    private function locked(TransportInfo $failed, string $id, callable $action): RetryOutcome
    {
        $lock = $this->lockFactory?->createLock('nowo_messenger_monitor_kit.' . sha1($failed->name() . "\0" . $id), $this->lockTtl);
        if ($lock instanceof LockInterface && !$lock->acquire()) {
            return new RetryOutcome(RetryStatus::Locked);
        }

        try {
            $queued = $failed->find($id);

            return $queued instanceof QueuedMessage ? $action($queued) : new RetryOutcome(RetryStatus::NotFound);
        } catch (Throwable $e) {
            $this->logger?->error('Messenger monitor: failed message action failed.', ['bundle' => 'messenger_monitor_kit', 'transport' => $failed->name(), 'id' => $id, 'exception' => $e]);

            throw $e;
        } finally {
            $lock?->release();
        }
    }
}
