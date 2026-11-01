<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Retry;

use Nowo\MessengerMonitorKitBundle\Retry\BatchResult;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Retry\RetryOutcome;
use Nowo\MessengerMonitorKitBundle\Retry\RetryStatus;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\OptionalMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Support\MemoryTransport;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Monitor;
use Nowo\MessengerMonitorKitBundle\Tests\Support\RecordingBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;
use Zenstruck\Messenger\Monitor\Transport\QueuedMessage;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;

final class FailedMessageRetrierTest extends TestCase
{
    private MemoryTransport $failed;

    private MemoryTransport $async;

    private RecordingBus $bus;

    protected function setUp(): void
    {
        $this->failed = new MemoryTransport();
        $this->async  = new MemoryTransport();
        $this->bus    = new RecordingBus();
    }

    #[Test]
    public function itDetectsFailureTransportsByNameOrConfiguration(): void
    {
        $retrier = $this->retrier();
        self::assertTrue($retrier->isFailureTransport('failed'));
        self::assertFalse($retrier->isFailureTransport('async'));

        $configured = $this->retrier(['dead_letters']);
        self::assertTrue($configured->isFailureTransport('dead_letters'));
        self::assertFalse($configured->isFailureTransport('failed'));
        self::assertNull($configured->failureTransport('failed'));
    }

    #[Test]
    public function itResolvesOnlyListableFailureTransports(): void
    {
        $retrier = $this->retrier([], ['failed' => $this->failed, 'async' => $this->async, 'failed_sync' => Monitor::sync()]);
        self::assertSame('failed', $retrier->failureTransport('failed')?->name());
        self::assertNull($retrier->failureTransport('async'));
        self::assertNull($retrier->failureTransport('unknown_failed'));
        self::assertNull($retrier->failureTransport('failed_sync'));
        self::assertSame(['async'], $retrier->queueTransports());
    }

    #[Test]
    public function itRetriesToTheOriginalTransportWithCleanStamps(): void
    {
        $id      = $this->park(new DemoMessage(), 'async');
        $retrier = $this->retrier();

        $outcome = $retrier->retry($this->failedInfo(), $id);

        self::assertSame(RetryStatus::Done, $outcome->status);
        self::assertSame('DemoMessage', $outcome->type);
        self::assertSame('async', $outcome->target);
        self::assertSame([$id], $this->failed->rejected);
        self::assertCount(1, $this->bus->dispatched);
        $sent = $this->bus->dispatched[0];
        self::assertNull($sent->last(SentToFailureTransportStamp::class));
        self::assertNull($sent->last(ReceivedStamp::class));
        self::assertNull($sent->last(TransportMessageIdStamp::class));
        self::assertSame(['async'], $sent->last(TransportNamesStamp::class)?->getTransportNames());
        self::assertNotNull($sent->last(HandledStamp::class), 'handled stamps are kept');
        self::assertSame(1, $sent->last(RedeliveryStamp::class)?->getRetryCount(), 'the redelivery counter is kept');
        self::assertCount(2, $sent->all(TagStamp::class));
    }

    #[Test]
    public function itFallsBackOrLeavesTheMessageParked(): void
    {
        $id = $this->park(new DemoMessage(), 'removed_transport');
        self::assertSame(RetryStatus::NotRetryable, $this->retrier()->retry($this->failedInfo(), $id)->status);
        self::assertSame([], $this->failed->rejected);

        $fallback = $this->retrier([], null, 'async');
        self::assertSame('async', $fallback->retry($this->failedInfo(), $id)->target);

        $noOrigin = $this->failed->send(new Envelope(new DemoMessage()));
        self::assertNull($this->retrier([], null, 'async')->targetFor(new QueuedMessage($noOrigin)));
        $invalidFallback = $this->park(new DemoMessage(), 'gone');
        self::assertSame(RetryStatus::NotRetryable, $this->retrier([], null, 'failed')->retry($this->failedInfo(), $invalidFallback)->status);
    }

    #[Test]
    public function itReportsMissingMessages(): void
    {
        self::assertSame(RetryStatus::NotFound, $this->retrier()->retry($this->failedInfo(), '999')->status);
        self::assertSame(RetryStatus::NotFound, $this->retrier()->discard($this->failedInfo(), '999')->status);
    }

    #[Test]
    public function itRemovesMessages(): void
    {
        $id      = $this->park(new DemoMessage(), 'async');
        $outcome = $this->retrier()->discard($this->failedInfo(), $id);
        self::assertTrue($outcome->isDone());
        self::assertSame([], $this->failed->envelopes);
        self::assertSame([], $this->bus->dispatched);
    }

    #[Test]
    public function itRetriesAndRemovesInBatches(): void
    {
        $this->park(new DemoMessage(), 'async');
        $this->park(new OptionalMessage(), 'async');
        $this->park(new DemoMessage(), 'gone');
        $this->park(new DemoMessage(), 'async');

        $result = $this->retrier()->retryAll($this->failedInfo(), 3);
        self::assertSame(2, $result->done);
        self::assertSame(1, $result->skipped);
        self::assertSame(['DemoMessage' => 1, 'OptionalMessage' => 1], $result->types);
        self::assertSame(['async'], $result->targets);

        $removed = $this->retrier()->discardAll($this->failedInfo(), 10);
        self::assertSame(2, $removed->done);
        self::assertSame([], $this->failed->envelopes);
    }

    #[Test]
    public function itLocksConcurrentActionsOnTheSameMessage(): void
    {
        $id    = $this->park(new DemoMessage(), 'async');
        $locks = new LockFactory(new InMemoryStore());
        $held  = $locks->createLock('nowo_messenger_monitor_kit.' . sha1("failed\0" . $id));
        self::assertTrue($held->acquire());

        $retrier = $this->retrier([], null, null, $locks);
        self::assertSame(RetryStatus::Locked, $retrier->retry($this->failedInfo(), $id)->status);
        $held->release();
        self::assertSame(RetryStatus::Done, $retrier->retry($this->failedInfo(), $id)->status);
        self::assertSame(RetryStatus::NotFound, $retrier->retry($this->failedInfo(), $id)->status, 'the second retry finds nothing');
    }

    #[Test]
    public function itRethrowsTransportErrors(): void
    {
        $id                       = $this->park(new DemoMessage(), 'async');
        $this->failed->failReject = true;

        $this->expectException(RuntimeException::class);
        $this->retrier()->discard($this->failedInfo(), $id);
    }

    #[Test]
    public function itFlagsDuplicateRiskClasses(): void
    {
        $retrier = $this->retrier([], null, null, null, [DemoMessage::class]);
        self::assertTrue($retrier->isDuplicateRisk(new QueuedMessage(new Envelope(new DemoMessage()))));
        self::assertFalse($retrier->isDuplicateRisk(new QueuedMessage(new Envelope(new OptionalMessage()))));
    }

    #[Test]
    public function batchResultsAndOutcomesAccumulate(): void
    {
        $result = (new BatchResult())
            ->with(new RetryOutcome(RetryStatus::Done, null, 'async'))
            ->with(new RetryOutcome(RetryStatus::Done, 'A', 'async'))
            ->with(new RetryOutcome(RetryStatus::Done, 'A'))
            ->with(new RetryOutcome(RetryStatus::Locked));
        self::assertSame(3, $result->done);
        self::assertSame(1, $result->skipped);
        self::assertSame(['?' => 1, 'A' => 2], $result->types);
        self::assertSame(['async'], $result->targets);
    }

    /**
     * @param list<string> $failureTransports
     * @param array<string, TransportInterface>|null $transports
     * @param list<string> $risky
     */
    private function retrier(array $failureTransports = [], ?array $transports = null, ?string $fallback = null, ?LockFactory $locks = null, array $risky = []): FailedMessageRetrier
    {
        return new FailedMessageRetrier(
            Monitor::transports($transports ?? ['failed' => $this->failed, 'async' => $this->async, 'sync' => Monitor::sync()]),
            $this->bus,
            $failureTransports,
            $fallback,
            $risky,
            $locks,
        );
    }

    private function failedInfo(): TransportInfo
    {
        return Monitor::transports(['failed' => $this->failed])->get('failed');
    }

    private function park(object $message, string $origin): string
    {
        $envelope = $this->failed->send(new Envelope($message, [
            new SentToFailureTransportStamp($origin),
            new ReceivedStamp($origin),
            new TransportNamesStamp(['failed']),
            new RedeliveryStamp(1),
            new HandledStamp('ok', 'App\\Handler'),
        ]));

        return (string) $envelope->last(TransportMessageIdStamp::class)?->getId();
    }
}
