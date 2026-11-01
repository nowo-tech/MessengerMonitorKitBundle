<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Fixtures;

/**
 * Test message with personal data the redaction must mask.
 */
final readonly class DemoMessage
{
    public function __construct(
        public string $orderId = 'order-42',
        public string $email = 'jane.doe@example.test',
        public string $note = 'Call +34 600 111 222 tomorrow',
        public ?string $apiToken = 'REDACTED-TEST-TOKEN',
    ) {
    }
}
