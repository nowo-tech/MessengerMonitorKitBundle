<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Retry;

/**
 * Outcome of a single retry / removal of a failed message.
 */
enum RetryStatus: string
{
    /** Re-dispatched (retry) or rejected from the failure transport (removal). */
    case Done = 'done';

    /** Not on the failure transport (already retried / removed, wrong id). */
    case NotFound = 'not_found';

    /** No original transport to send it back to (and no fallback): left parked. */
    case NotRetryable = 'not_retryable';

    /** Another request is retrying / removing the same message right now. */
    case Locked = 'locked';
}
