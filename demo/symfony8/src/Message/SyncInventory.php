<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Pushes a stock level to a supplier API (fails for unknown SKUs in the demo).
 */
final readonly class SyncInventory
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public string $apiToken = 'REDACTED-DEMO-TOKEN',
    ) {
    }
}
