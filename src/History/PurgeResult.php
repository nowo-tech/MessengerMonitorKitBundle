<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\History;

/**
 * Outcome of a purge: deleted rows and whether every matching row was deleted (false when a batch cap stopped it).
 */
final readonly class PurgeResult
{
    /**
     * @param int $deleted rows deleted
     * @param bool $complete false when the batch cap was reached and matching rows may remain
     */
    public function __construct(
        public int $deleted,
        public bool $complete = true,
    ) {
    }
}
