<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Health;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\MessengerMonitorKitBundle\Health\QueueDepthProbe;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Support\MemoryTransport;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Monitor;
use Nowo\MessengerMonitorKitBundle\Tests\Support\RecordingBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class QueueDepthProbeTest extends TestCase
{
    #[Test]
    public function itComparesPendingAndFailedTotalsWithTheThresholds(): void
    {
        $async  = new MemoryTransport();
        $failed = new MemoryTransport();
        $depth  = $this->depth($async, $failed);

        $ok = (new QueueDepthProbe($depth))->check(1.0);
        self::assertSame(HealthStatus::Ok, $ok->status);
        self::assertSame(['pending' => 0, 'failed' => 0, 'transport.async' => 0, 'transport.failed' => 0], $ok->data);

        $async->send(new Envelope(new DemoMessage()));
        $async->send(new Envelope(new DemoMessage()));
        $failed->send(new Envelope(new DemoMessage()));

        $degraded = (new QueueDepthProbe($depth, 2, 10, 5))->check(1.0);
        self::assertSame(HealthStatus::Degraded, $degraded->status);
        self::assertSame('2 pending messages (warning threshold 2).', $degraded->message);

        $error = (new QueueDepthProbe($depth, null, null, 1, 1))->check(1.0);
        self::assertSame(HealthStatus::Error, $error->status);
        self::assertSame('1 failed messages (fail threshold 1).', $error->message);
    }

    #[Test]
    public function itDegradesWhenNothingCanBeCounted(): void
    {
        $transports = Monitor::transports(['memory' => new InMemoryTransport()]);
        $probe      = new QueueDepthProbe(new QueueDepth($transports, new FailedMessageRetrier($transports, new RecordingBus())));

        self::assertSame(HealthStatus::Degraded, $probe->check(1.0)->status);

        $failedOnly = Monitor::transports(['memory' => new InMemoryTransport(), 'failed' => new MemoryTransport()]);
        $onlyFailed = new QueueDepthProbe(new QueueDepth($failedOnly, new FailedMessageRetrier($failedOnly, new RecordingBus())), 0, 0);
        self::assertSame(HealthStatus::Ok, $onlyFailed->check(1.0)->status, 'no pending count: the pending thresholds are skipped');
    }

    private function depth(MemoryTransport $async, MemoryTransport $failed): QueueDepth
    {
        $transports = Monitor::transports(['async' => $async, 'failed' => $failed]);

        return new QueueDepth($transports, new FailedMessageRetrier($transports, new RecordingBus()));
    }
}
