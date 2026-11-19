<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * DBAL errors as the drivers raise them (vendor code + SQLSTATE), for the conflict-mapping tests.
 */
final class DbalErrors
{
    public static function deadlock(): DeadlockException
    {
        return new DeadlockException(self::driver('Deadlock found when trying to get lock', '40001', 1213), null);
    }

    public static function lockWaitTimeout(): LockWaitTimeoutException
    {
        return new LockWaitTimeoutException(self::driver('Lock wait timeout exceeded', 'HY000', 1205), null);
    }

    public static function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(self::driver('Duplicate entry', '23000', 1062), null);
    }

    public static function connectionLost(): ConnectionLost
    {
        return new ConnectionLost(self::driver('MySQL server has gone away', 'HY000', 2006), null);
    }

    private static function driver(string $message, string $sqlState, int $code): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }
}
