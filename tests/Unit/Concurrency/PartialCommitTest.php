<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Concurrency;

use Nowo\MessengerMonitorKitBundle\Concurrency\PartialCommit;
use Nowo\MessengerMonitorKitBundle\Tests\Support\DbalErrors;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PartialCommitTest extends TestCase
{
    #[Test]
    public function aLockConflictBecomesACommittedConflict(): void
    {
        $deadlock = DbalErrors::deadlock();
        $wrapped  = new RuntimeException('transport', 0, $deadlock);
        $conflict = PartialCommit::conflict($wrapped);

        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertSame(TransientFailureKind::Deadlock, $conflict->kind);
        self::assertSame(1, $conflict->attempts);
        self::assertSame($wrapped, $conflict->getPrevious());
        self::assertNull($conflict->retryAfterSeconds());
    }

    #[Test]
    public function anUncommittedConflictIsMarkedCommittedAndACommittedOneIsKept(): void
    {
        $uncommitted = new ConcurrencyConflict(3, TransientFailureKind::LockWaitTimeout, 2, DbalErrors::lockWaitTimeout());
        $marked      = PartialCommit::conflict($uncommitted);
        self::assertInstanceOf(ConcurrencyConflict::class, $marked);
        self::assertTrue($marked->committed);
        self::assertSame(3, $marked->attempts);
        self::assertSame(TransientFailureKind::LockWaitTimeout, $marked->kind);

        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, DbalErrors::deadlock(), committed: true);
        $outer     = new RuntimeException('outer', 0, $committed);
        self::assertSame($outer, PartialCommit::conflict($outer));
    }

    #[Test]
    public function otherFailuresAreReturnedAsTheyAre(): void
    {
        foreach ([DbalErrors::uniqueViolation(), DbalErrors::connectionLost(), new RuntimeException('bug')] as $failure) {
            self::assertSame($failure, PartialCommit::conflict($failure));
        }
    }
}
