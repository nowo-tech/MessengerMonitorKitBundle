<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Redacted, size-capped JSON of a message payload (or of handler results) for the UI. The string is printed by Twig
 * with auto-escaping inside a `<pre>`; it is never marked safe. Stateless.
 */
final readonly class PayloadView
{
    /**
     * @param bool $enabled `payload.enabled` (false = {@see render()} returns null)
     */
    public function __construct(
        private PayloadNormalizer $normalizer,
        private Redactor $redactor,
        private bool $enabled = true,
    ) {
    }

    /**
     * Whether payloads may be shown at all.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Pretty JSON of the redacted message, or null when payloads are disabled.
     */
    public function render(object $message): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->json($this->redactor->redact($this->normalizer->normalize($message), $message::class));
    }

    /**
     * Pretty JSON of an already normalised array (handler results), redacted.
     *
     * @param array<array-key, mixed> $data
     */
    public function renderData(array $data, ?string $messageClass = null): string
    {
        return $this->json($this->redactor->redact($data, $messageClass));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
