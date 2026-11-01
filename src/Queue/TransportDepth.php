<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Queue;

/**
 * Message count of one transport.
 */
final readonly class TransportDepth
{
    /**
     * @param string $name transport name
     * @param int|null $count messages waiting; null = could not be counted (not countable or broker error)
     * @param bool $failure whether it is a failure transport
     * @param bool $listable whether its messages can be listed (failed message pages)
     */
    public function __construct(
        public string $name,
        public ?int $count,
        public bool $failure = false,
        public bool $listable = false,
    ) {
    }
}
