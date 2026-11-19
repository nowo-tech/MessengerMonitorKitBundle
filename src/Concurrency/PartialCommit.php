<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Concurrency;

use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Throwable;

/**
 * Since 1.3.0 (REQ-RUNTIME-003 rule 6): marks a lock conflict met after part of an action was already committed.
 *
 * The bundle's admin actions are several auto-committed statements, not one transaction: a retry sends the message
 * to its transport (committed) and then rejects it from the failure transport; "retry all" / "remove all" act on one
 * message after the other; a purge deletes one keyset batch after the other. When a deadlock, lock wait timeout or
 * serialization failure stops such an action after its first committed step, the failure is wrapped in a
 * {@see ConcurrencyConflict} with `committed: true`, so the answer is `CONFLICT_002` (read again, do not repeat) and
 * not `CONFLICT_001`. Any other failure (unique violation, lost connection, a bug) is returned unchanged. Stateless.
 */
final class PartialCommit
{
    /**
     * The failure to throw after a committed step: a committed {@see ConcurrencyConflict} wrapping `$failure` when the
     * chain holds a lock conflict, else `$failure` itself (also when it already carries a ConcurrencyConflict).
     */
    public static function conflict(Throwable $failure): Throwable
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict) {
                return $current->committed ? $failure : new ConcurrencyConflict($current->attempts, $current->kind, null, $failure, committed: true);
            }
        }

        $kind = (new TransientFailureClassifier())->classify($failure);

        return $kind instanceof TransientFailureKind ? new ConcurrencyConflict(1, $kind, null, $failure, committed: true) : $failure;
    }
}
