<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use function is_string;

/**
 * Redaction chain of the monitor: the built-in {@see KeyPatternRedactor} first, then every tagged
 * {@see PayloadRedactorInterface} of the host. Used for payloads, handler results (also before they are stored) and
 * failure messages. Stateless.
 */
final readonly class Redactor
{
    /**
     * @param iterable<PayloadRedactorInterface> $redactors host redactors (tag `nowo_messenger_monitor_kit.payload_redactor`)
     */
    public function __construct(
        private KeyPatternRedactor $default = new KeyPatternRedactor(),
        private iterable $redactors = [],
    ) {
    }

    /**
     * Redacts a normalised array through the whole chain.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $data, ?string $messageClass = null): array
    {
        $data = $this->default->redact($data, $messageClass);
        foreach ($this->redactors as $redactor) {
            $data = $redactor->redact($data, $messageClass);
        }

        return $data;
    }

    /**
     * Redacts a free text (pattern masks of the built-in redactor; host redactors see it as `['text' => …]`).
     */
    public function redactText(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $text = $this->default->redactText($text);
        foreach ($this->redactors as $redactor) {
            $redacted = $redactor->redact(['text' => $text]);
            $text     = isset($redacted['text']) && is_string($redacted['text']) ? $redacted['text'] : $text;
        }

        return $text;
    }
}
