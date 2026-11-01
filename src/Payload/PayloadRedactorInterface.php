<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

/**
 * Redaction hook for message payloads and handler results shown (or stored) by the monitor. Services implementing it
 * are autoconfigured with the `nowo_messenger_monitor_kit.payload_redactor` tag and run after the built-in key /
 * pattern redactor, in tag priority order.
 */
interface PayloadRedactorInterface
{
    /**
     * Returns `$data` with personal data / secrets replaced. Must not throw and must keep the array shape.
     *
     * @param array<array-key, mixed> $data normalised payload (scalars, null and nested arrays only)
     * @param string|null $messageClass class of the message the data belongs to, when known
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $data, ?string $messageClass = null): array;
}
