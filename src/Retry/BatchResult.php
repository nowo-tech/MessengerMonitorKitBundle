<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Retry;

use function in_array;

/**
 * Result of "retry all" / "remove all" on a failure transport (at most `retry.batch_limit` messages).
 */
final readonly class BatchResult
{
    /**
     * @param int $done messages retried / removed
     * @param int $skipped messages left parked (not retryable, locked or gone)
     * @param array<string, int> $types short class name => count of the messages done
     * @param list<string> $targets distinct target transports (retry)
     */
    public function __construct(
        public int $done = 0,
        public int $skipped = 0,
        public array $types = [],
        public array $targets = [],
    ) {
    }

    /**
     * Adds one outcome.
     */
    public function with(RetryOutcome $outcome): self
    {
        if (!$outcome->isDone()) {
            return new self($this->done, $this->skipped + 1, $this->types, $this->targets);
        }

        $types        = $this->types;
        $type         = $outcome->type ?? '?';
        $types[$type] = ($types[$type] ?? 0) + 1;
        $targets      = $this->targets;
        if ($outcome->target !== null && !in_array($outcome->target, $targets, true)) {
            $targets[] = $outcome->target;
        }

        return new self($this->done + 1, $this->skipped, $types, $targets);
    }
}
