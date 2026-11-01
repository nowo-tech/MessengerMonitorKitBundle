<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Nowo\MessengerMonitorKitBundle\Doctrine\KeysetDeleter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeysetDeleterTest extends TestCase
{
    #[Test]
    public function itDeletesInBatchesAndStopsAtTheCap(): void
    {
        $connection = $this->connection(25);
        $deleter    = new KeysetDeleter($connection);

        $partial = $deleter->delete('t', 'id', 'kind = ?', ['a'], ['string'], 5, 2);
        self::assertSame(10, $partial->deleted);
        self::assertFalse($partial->complete);

        $rest = $deleter->delete('t', 'id', 'kind = ?', ['a'], ['string'], 5);
        self::assertTrue($rest->complete);
        self::assertSame(3, $rest->deleted, '13 "a" rows in total');
        self::assertSame(12, (int) $connection->fetchOne('SELECT COUNT(*) FROM t'));

        $all = $deleter->delete('t', 'id', '', [], [], 100);
        self::assertSame(12, $all->deleted);
        self::assertTrue($all->complete);
        self::assertSame(0, $deleter->delete('t', 'id', '', [], [], 100)->deleted);
    }

    #[Test]
    public function aFullLastBatchEndsOnTheNextEmptySelect(): void
    {
        $result = (new KeysetDeleter($this->connection(10)))->delete('t', 'id', '', [], [], 5, 2);
        self::assertSame(10, $result->deleted);
        self::assertFalse($result->complete, 'the cap stops before proving no row is left');
    }

    #[Test]
    public function itRefusesUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new KeysetDeleter($this->connection(0)))->delete('t; DROP TABLE t', 'id', '', [], [], 10);
    }

    private function connection(int $rows): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, kind VARCHAR(5))');
        for ($i = 1; $i <= $rows; ++$i) {
            $connection->insert('t', ['id' => $i, 'kind' => $i % 2 === 1 ? 'a' : 'b']);
        }

        return $connection;
    }
}
