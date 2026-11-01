<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Queue;

use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepthReport;
use Nowo\MessengerMonitorKitBundle\Queue\TransportDepth;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Support\MemoryTransport;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Monitor;
use Nowo\MessengerMonitorKitBundle\Tests\Support\RecordingBus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class QueueDepthTest extends TestCase
{
    #[Test]
    public function itCountsQueuesAndFailureTransports(): void
    {
        $async  = new MemoryTransport();
        $failed = new MemoryTransport();
        $async->send(new Envelope(new DemoMessage()));
        $async->send(new Envelope(new DemoMessage()));
        $failed->send(new Envelope(new DemoMessage()));
        $broken            = new MemoryTransport();
        $broken->failCount = true;
        $logger            = $this->logger();

        $transports = Monitor::transports(['async' => $async, 'failed' => $failed, 'broken' => $broken, 'memory' => new InMemoryTransport(), 'sync' => Monitor::sync()]);
        $report     = (new QueueDepth($transports, new FailedMessageRetrier($transports, new RecordingBus()), [], $logger))->report();

        self::assertSame(['async' => 2, 'failed' => 1, 'broken' => null, 'memory' => null], $report->toArray());
        self::assertSame(2, $report->pending());
        self::assertSame(1, $report->failed());
        self::assertTrue($report->get('failed')->failure ?? null);
        self::assertTrue($report->get('failed')->listable ?? null);
        self::assertNull($report->get('nope'));
        self::assertSame(['Messenger queue depth unavailable.'], $logger->messages);
    }

    #[Test]
    public function itCountsOnlyTheConfiguredTransports(): void
    {
        $transports = Monitor::transports(['async' => new MemoryTransport(), 'other' => new MemoryTransport()]);
        $report     = (new QueueDepth($transports, new FailedMessageRetrier($transports, new RecordingBus()), ['other']))->report();

        self::assertSame(['other' => 0], $report->toArray());
        self::assertNull($report->failed());
        self::assertNull((new QueueDepthReport([new TransportDepth('x', null)]))->pending());
    }

    /**
     * @return AbstractLogger&object{messages: list<string>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
    }
}
