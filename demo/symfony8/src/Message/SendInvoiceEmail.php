<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Sends an invoice by e-mail (personal data: the monitor shows it redacted).
 */
final readonly class SendInvoiceEmail
{
    public function __construct(
        public string $invoiceNumber,
        public string $customerEmail,
        public string $customerPhone,
        public int $amountCents,
    ) {
    }
}
