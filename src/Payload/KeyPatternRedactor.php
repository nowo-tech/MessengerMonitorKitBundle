<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use function array_map;
use function is_array;
use function is_int;
use function is_string;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;

/**
 * Built-in redactor: masks the value of every key whose normalised name contains a configured key (case-insensitive,
 * `_` / `-` ignored, so `api_key`, `apiKey` and `X-Api-Key` all match `apikey`), and masks e-mail addresses and runs
 * of 9+ digits inside any string. Over-redaction is the intended failure mode.
 */
final readonly class KeyPatternRedactor implements PayloadRedactorInterface
{
    /** Default `redaction.keys`. */
    public const DEFAULT_KEYS = [
        'password', 'passwd', 'secret', 'token', 'apikey', 'authorization', 'cookie', 'session', 'credential',
        'privatekey', 'signature', 'iban', 'cardnumber', 'cvv', 'cvc', 'pin', 'ssn', 'email', 'phone', 'mobile',
        'address', 'birth', 'taxid', 'vat', 'passport', 'ipaddress', 'to', 'cc', 'bcc', 'from', 'replyto',
    ];

    /** Default `redaction.mask`. */
    public const DEFAULT_MASK = '[redacted]';

    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /** Digit runs with optional spaces / dashes; masked when they hold {@see self::LONG_NUMBER_DIGITS}+ digits. */
    private const LONG_NUMBER_PATTERN = '/\+?\d[\d \-]{6,}\d/';

    private const LONG_NUMBER_DIGITS = 9;

    /** Keys shorter than this must match exactly (avoids `to` masking `total`). */
    private const EXACT_MATCH_BELOW = 4;

    /** @var list<string> */
    private array $keys;

    /**
     * @param iterable<string> $keys key names to mask
     * @param string $mask replacement value
     * @param bool $maskEmails mask e-mail addresses inside strings
     * @param bool $maskLongNumbers mask runs of 9+ digits inside strings
     */
    public function __construct(
        iterable $keys = self::DEFAULT_KEYS,
        private string $mask = self::DEFAULT_MASK,
        private bool $maskEmails = true,
        private bool $maskLongNumbers = true,
    ) {
        $normalised = [];
        foreach ($keys as $key) {
            $normalised[] = $this->normalise($key);
        }
        $this->keys = $normalised;
    }

    /**
     * Masks sensitive keys and patterns recursively.
     */
    public function redact(array $data, ?string $messageClass = null): array
    {
        $redacted = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = is_array($value) ? array_map(fn (): string => $this->mask, $value) : $this->mask;

                continue;
            }
            $redacted[$key] = match (true) {
                is_array($value)  => $this->redact($value, $messageClass),
                is_string($value) => $this->redactText($value),
                default           => $value,
            };
        }

        return $redacted;
    }

    /**
     * Masks e-mail addresses and long digit runs inside a free text (failure messages, log-like strings).
     */
    public function redactText(string $text): string
    {
        if ($this->maskEmails) {
            $text = (string) preg_replace(self::EMAIL_PATTERN, $this->mask, $text);
        }
        if ($this->maskLongNumbers) {
            $text = (string) preg_replace_callback(self::LONG_NUMBER_PATTERN, fn (array $m): string => preg_match_all('/\d/', $m[0]) >= self::LONG_NUMBER_DIGITS ? $this->mask : $m[0], $text);
        }

        return $text;
    }

    /**
     * Whether a key name is masked.
     */
    public function isSensitiveKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }
        $normalised = $this->normalise($key);
        foreach ($this->keys as $sensitive) {
            if ($sensitive === '') {
                continue;
            }
            if (strlen($sensitive) < self::EXACT_MATCH_BELOW ? $normalised === $sensitive : str_contains($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $key): string
    {
        return strtolower(str_replace(['_', '-', ' ', '.'], '', $key));
    }
}
