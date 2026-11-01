<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Queue;

/**
 * Queue depth of every monitored transport at one instant.
 */
final readonly class QueueDepthReport
{
    /**
     * @param list<TransportDepth> $transports
     */
    public function __construct(
        public array $transports = [],
    ) {
    }

    /**
     * Messages waiting on the non-failure transports; null when none could be counted.
     */
    public function pending(): ?int
    {
        return $this->sum(false);
    }

    /**
     * Messages parked on the failure transports; null when none could be counted.
     */
    public function failed(): ?int
    {
        return $this->sum(true);
    }

    /**
     * Depth of one transport, or null.
     */
    public function get(string $name): ?TransportDepth
    {
        foreach ($this->transports as $transport) {
            if ($transport->name === $name) {
                return $transport;
            }
        }

        return null;
    }

    /**
     * Name => count map (metrics, health data).
     *
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        $counts = [];
        foreach ($this->transports as $transport) {
            $counts[$transport->name] = $transport->count;
        }

        return $counts;
    }

    private function sum(bool $failure): ?int
    {
        $total = null;
        foreach ($this->transports as $transport) {
            if ($transport->failure === $failure && $transport->count !== null) {
                $total = ($total ?? 0) + $transport->count;
            }
        }

        return $total;
    }
}
