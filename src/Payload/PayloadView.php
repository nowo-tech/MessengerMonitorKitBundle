<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use function json_encode;
use function mb_strcut;
use function strlen;

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
    /** Default `payload.max_bytes`. */
    public const DEFAULT_MAX_BYTES = 262_144;

    /** Appended to a JSON cut at the byte budget. */
    public const TRUNCATED_MARKER = "\n… (truncated)";

    /**
     * @param bool $enabled `payload.enabled` (false = {@see render()} returns null)
     * @param int $maxBytes `payload.max_bytes`: bytes of JSON kept
     */
    public function __construct(
        private PayloadNormalizer $normalizer,
        private Redactor $redactor,
        private bool $enabled = true,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
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
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen($json) > $this->maxBytes ? mb_strcut($json, 0, $this->maxBytes, 'UTF-8') . self::TRUNCATED_MARKER : $json;
    }
}
