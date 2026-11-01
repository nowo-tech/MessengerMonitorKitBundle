<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Queue;

use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Psr\Log\LoggerInterface;
use Throwable;
use Zenstruck\Messenger\Monitor\Transports;

use function in_array;
use function max;

/**
 * Instance-wide Messenger queue depth (dashboard, HealthKit probe, host metrics): the message count of every countable
 * transport (`queue_depth.transports`, default all; sync and scheduler excluded), failure transports apart. A broker
 * error makes one count null and logs a warning; it never throws. Stateless: one broker call per transport and call.
 */
final readonly class QueueDepth
{
    /**
     * @param list<string> $only transports to count (empty = every countable one)
     */
    public function __construct(
        private Transports $transports,
        private FailedMessageRetrier $retrier,
        private array $only = [],
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Counts every monitored transport now.
     */
    public function report(): QueueDepthReport
    {
        $depths = [];
        foreach ($this->transports->filter()->excludeSync()->excludeSchedules() as $name => $transport) {
            if ($this->only !== [] && !in_array($name, $this->only, true)) {
                continue;
            }
            $count = null;
            if ($transport->isCountable()) {
                try {
                    $count = max(0, $transport->count());
                } catch (Throwable $e) {
                    $this->logger?->warning('Messenger queue depth unavailable.', ['bundle' => 'messenger_monitor_kit', 'transport' => $name, 'exception' => $e]);
                }
            }
            $depths[] = new TransportDepth($name, $count, $this->retrier->isFailureTransport($name), $transport->isListable());
        }

        return new QueueDepthReport($depths);
    }
}
