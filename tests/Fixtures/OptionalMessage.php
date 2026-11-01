<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Fixtures;

/**
 * High-volume test message (recording.optional_message_classes).
 */
final readonly class OptionalMessage
{
    public function __construct(
        public int $sequence = 1,
    ) {
    }
}
