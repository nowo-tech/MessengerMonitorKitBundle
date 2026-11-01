<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendInvoiceEmail;
use App\Message\SyncInventory;
use App\Message\TrackPageView;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function usleep;

/**
 * Demo handlers: invoices to *.invalid addresses and SKUs starting with "OLD-" fail, everything else succeeds.
 */
final class DemoHandlers
{
    /**
     * Pretends to send the invoice.
     */
    #[AsMessageHandler]
    public function sendInvoice(SendInvoiceEmail $message): string
    {
        usleep(20_000);
        if (str_ends_with($message->customerEmail, '.invalid')) {
            throw new RuntimeException(sprintf('SMTP 550: mailbox %s unavailable', $message->customerEmail));
        }

        return sprintf('Invoice %s queued for %s', $message->invoiceNumber, $message->customerEmail);
    }

    /**
     * Pretends to call the supplier API.
     */
    #[AsMessageHandler]
    public function syncInventory(SyncInventory $message): void
    {
        usleep(10_000);
        if (str_starts_with($message->sku, 'OLD-')) {
            throw new RuntimeException(sprintf('Supplier API 404: unknown SKU %s', $message->sku));
        }
    }

    /**
     * Pretends to store a page view.
     */
    #[AsMessageHandler]
    public function trackPageView(TrackPageView $message): void
    {
    }
}
