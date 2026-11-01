<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Retry;

/**
 * Result of a single retry / removal: status, message short name and (retry) target transport.
 */
final readonly class RetryOutcome
{
    /**
     * @param string|null $type short class name of the message (null when not found)
     * @param string|null $target transport the message was sent to (retry only)
     */
    public function __construct(
        public RetryStatus $status,
        public ?string $type = null,
        public ?string $target = null,
    ) {
    }

    /**
     * Whether the action happened.
     */
    public function isDone(): bool
    {
        return $this->status === RetryStatus::Done;
    }
}
