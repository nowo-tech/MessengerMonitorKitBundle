<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use function array_map;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Built-in redactor.
 *
 * Keys: the value of every key whose normalised name (case-insensitive, `_` / `-` / `.` / spaces ignored) contains a
 * configured key is masked; keys of 2 characters, and keys written with a leading `=` (`=name`), match only
 * the whole name (`to` does not mask `total`, `=name` does not mask `transportName`, `=pin` does not mask `shipping`).
 *
 * Free text (string values, failure messages, descriptions): credentials are always masked (URL userinfo,
 * `Bearer` / `Basic` values, `password=` / `token:` / `api_key=`… pairs, well-known key prefixes such as `sk_live_`,
 * `ghp_`, `xoxb-`, `AKIA…`, JWTs); with the options, e-mail addresses (Unicode local parts and domains, dot-less hosts)
 * and national ids / long numbers (Spanish DNI / NIE, runs of 9+ digits separated by spaces, `-`, `.`, `/` or
 * parentheses — cards, phones, accounts; ISO dates are kept). Over-redaction is the intended failure mode.
 */
final readonly class KeyPatternRedactor implements PayloadRedactorInterface
{
    /** Default `redaction.keys`. */
    public const DEFAULT_KEYS = [
        'password', 'passwd', 'pwd', 'pass', 'secret', 'token', 'apikey', 'accesskey', 'encryptionkey', 'privatekey', '=key',
        'authorization', 'auth', 'jwt', 'bearer', 'cookie', 'session', 'credential', 'signature', 'otp', 'totp', '=code',
        'verificationcode', 'dsn', 'databaseurl', 'webhookurl', 'iban', 'cardnumber', '=pan', 'cvv', 'cvc', '=pin', 'pincode', 'ssn',
        'dni', 'nif', '=nie', 'nienumber', 'taxid', '=vat', 'vatid', 'vatnumber', 'passport', 'email', 'phone', 'mobile', 'address', 'postcode', '=zip', 'birth',
        'dob', 'ip', 'ipaddress', 'clientip', 'remoteip', 'username', '=login', '=name', 'firstname', 'lastname',
        'fullname', 'surname', 'customername', 'contactname', 'holdername', 'recipient', 'to', 'cc', 'bcc', 'from', 'replyto',
    ];

    /** Default `redaction.mask`. */
    public const DEFAULT_MASK = '[redacted]';

    /** Credential patterns (always applied), each replaced with `$1` + mask. */
    private const CREDENTIAL_PATTERNS = [
        '#([a-z][a-z0-9+.\-]*://)\S*(?=@[^@\s/]*(?:[/:?\#]|\s|$))#i',
        '#\b((?:bearer|basic)\s+)[A-Za-z0-9\-._~+/]+=*#i',
        '~(?<![A-Za-z0-9])((?:[A-Za-z0-9]+[_-])*(?:password|passwd|pwd|pass|passphrase|secret|token|api[_-]?key|access[_-]?key|key|auth|otp|dsn)\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;&"\']+)~i',
        '~()\b(?:[sprk]k_(?:live|test)_[A-Za-z0-9]+|gh[pousr]_[A-Za-z0-9]{10,}|xox[abprs]-[A-Za-z0-9\-]+|AKIA[0-9A-Z]{16}|eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*)~',
    ];

    private const EMAIL_PATTERN = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*/u';

    /** Spanish DNI (8 digits + letter) and NIE (X / Y / Z + 7 digits + letter). */
    private const NATIONAL_ID_PATTERN = '/\b[XYZ]?\d{7,8}[A-Z]\b/i';

    /** Digit runs with optional separators; masked when they hold {@see self::LONG_NUMBER_DIGITS}+ digits. */
    private const LONG_NUMBER_PATTERN = '~\+?\(?\d[\d \-./()]{6,}\d~';

    private const LONG_NUMBER_DIGITS = 9;

    /** A real ISO date inside a digit run (kept; the rest of the run is tested on its own). */
    private const ISO_DATE_PATTERN = '/((?<!\d)(?:19|20)\d{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])(?!\d))/';

    /** Keys shorter than this must match exactly (avoids `to` masking `total`, `ip` masking `zip`). */
    private const EXACT_MATCH_BELOW = 3;

    /** @var list<string> keys matched inside a name */
    private array $partialKeys;

    /** @var list<string> keys matched as the whole name */
    private array $exactKeys;

    /**
     * @param iterable<string> $keys key names to mask (`=name` = whole name only)
     * @param string $mask replacement value
     * @param bool $maskEmails mask e-mail addresses inside strings
     * @param bool $maskLongNumbers mask national ids and runs of 9+ digits inside strings
     */
    public function __construct(
        iterable $keys = self::DEFAULT_KEYS,
        private string $mask = self::DEFAULT_MASK,
        private bool $maskEmails = true,
        private bool $maskLongNumbers = true,
    ) {
        $partial = [];
        $exact   = [];
        foreach ($keys as $key) {
            $normalised = $this->normalise($key);
            if ($normalised === '') {
                continue;
            }
            if (str_starts_with($key, '=') || strlen($normalised) < self::EXACT_MATCH_BELOW) {
                $exact[] = $normalised;
            } else {
                $partial[] = $normalised;
            }
        }
        $this->partialKeys = $partial;
        $this->exactKeys   = $exact;
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
     * Masks credentials, e-mail addresses, national ids and long digit runs inside a free text.
     */
    public function redactText(string $text): string
    {
        foreach (self::CREDENTIAL_PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, '$1' . $this->mask, $text);
        }
        if ($this->maskEmails) {
            $text = (string) preg_replace(self::EMAIL_PATTERN, $this->mask, $text);
        }
        if ($this->maskLongNumbers) {
            $text = (string) preg_replace(self::NATIONAL_ID_PATTERN, $this->mask, $text);
            $text = (string) preg_replace_callback(self::LONG_NUMBER_PATTERN, fn (array $m): string => $this->maskNumberRun($m[0]), $text);
        }

        return $text;
    }

    /**
     * Masks the parts of a digit run holding {@see self::LONG_NUMBER_DIGITS}+ digits; real ISO dates inside it are kept
     * (`2026-11-01 4111 1111 1111 1111` → `2026-11-01 [redacted]`, `2100-0418-45-0200051332` → `[redacted]`).
     */
    private function maskNumberRun(string $run): string
    {
        $masked = '';
        foreach ((array) preg_split(self::ISO_DATE_PATTERN, $run, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part) {
            $part = (string) $part;
            $masked .= $index % 2 === 1 || preg_match_all('/\d/', $part) < self::LONG_NUMBER_DIGITS ? $part : (string) preg_replace('/[+(]*\d.*\d\)?/s', $this->mask, $part);
        }

        return $masked;
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
        if (in_array($normalised, $this->exactKeys, true)) {
            return true;
        }
        foreach ($this->partialKeys as $sensitive) {
            if (str_contains($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function normalise(string $key): string
    {
        return strtolower(str_replace(['=', '_', '-', ' ', '.'], '', $key));
    }
}
