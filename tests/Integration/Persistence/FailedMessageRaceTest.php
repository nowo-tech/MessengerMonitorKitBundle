<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\MessengerMonitorKitBundle\Controller\TransportsController;
use Nowo\MessengerMonitorKitBundle\Doctrine\KeysetDeleter;
use Nowo\MessengerMonitorKitBundle\EventListener\MessengerMonitorConflictListener;
use Nowo\MessengerMonitorKitBundle\Retry\RetryOutcome;
use Nowo\MessengerMonitorKitBundle\Retry\RetryStatus;
use Nowo\MessengerMonitorKitBundle\Tests\Support\RaceAdmin;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as MessengerConnection;
use Throwable;

use function getenv;
use function range;

/**
 * REQ-RUNTIME-003: two real connections on the same MySQL 8.4, MariaDB 11.4 or PostgreSQL 16 database (CI job
 * "Databases"; skipped when `NOWO_TEST_MYSQL_URL` / `NOWO_TEST_MARIADB_URL` / `NOWO_TEST_PGSQL_URL` are not set).
 *
 * Admin A and admin B each have their own DBAL connection, Doctrine failure / async transports and lock store
 * (DoctrineDbalStore on their own connection). A's bus runs B's action while A is between the dispatch and the
 * reject, which is the race window. Lock waits are capped at one second, so a hang fails fast.
 */
#[Group('database')]
final class FailedMessageRaceTest extends TestCase
{
    /** @var list<DbalConnection> */
    private array $connections = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'mysql' => [(string) getenv('NOWO_TEST_MYSQL_URL')];
        yield 'mariadb' => [(string) getenv('NOWO_TEST_MARIADB_URL')];
        yield 'postgresql' => [(string) getenv('NOWO_TEST_PGSQL_URL')];
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        $this->connections = [];
    }

    #[DataProvider('databases')]
    public function testRetryVersusRemoveKeepsOneOutcome(string $url): void
    {
        [$a, $b] = $this->admins($url);
        $id      = $a->seed();
        $removal = null;
        $a->beforeDispatch(static function () use ($b, $id, &$removal): void {
            $removal = $b->retrier()->discard($b->failedInfo(), $id);
        });

        $retry = $a->retrier()->retry($a->failedInfo(), $id);

        self::assertSame(RetryStatus::Done, $retry->status);
        self::assertInstanceOf(RetryOutcome::class, $removal);
        self::assertSame(RetryStatus::Locked, $removal->status, 'B cannot remove the message A is retrying');
        self::assertSame(0, $a->failed->getMessageCount());
        self::assertSame(1, $a->async->getMessageCount(), 'sent back exactly once');
        self::assertSame(RetryStatus::NotFound, $b->retrier()->discard($b->failedInfo(), $id)->status, 'B finds nothing once A is done');
    }

    #[DataProvider('databases')]
    public function testRetryVersusRetryDispatchesOnce(string $url): void
    {
        [$a, $b] = $this->admins($url);
        $id      = $a->seed();
        $second  = null;
        $a->beforeDispatch(static function () use ($b, $id, &$second): void {
            $second = $b->retrier()->retry($b->failedInfo(), $id);
        });

        self::assertSame(RetryStatus::Done, $a->retrier()->retry($a->failedInfo(), $id)->status);
        self::assertInstanceOf(RetryOutcome::class, $second);
        self::assertSame(RetryStatus::Locked, $second->status);
        self::assertSame(1, $a->async->getMessageCount());
        self::assertSame(RetryStatus::NotFound, $b->retrier()->retry($b->failedInfo(), $id)->status, 'a late retry finds nothing');
        self::assertSame(1, $a->async->getMessageCount());
    }

    #[DataProvider('databases')]
    public function testRemoveThenRetryFindsNothing(string $url): void
    {
        [$a, $b] = $this->admins($url);
        $id      = $a->seed();

        self::assertSame(RetryStatus::Done, $b->retrier()->discard($b->failedInfo(), $id)->status);
        self::assertSame(RetryStatus::NotFound, $a->retrier()->retry($a->failedInfo(), $id)->status, 'looked up again inside the lock');
        self::assertSame(0, $a->async->getMessageCount());
        self::assertSame(0, $a->failed->getMessageCount());
    }

    #[DataProvider('databases')]
    public function testWithoutALockTheSameRaceDispatchesTwice(string $url): void
    {
        // Why the bundle always wires a lock factory since 1.3.0: without one this race sends the message back twice.
        [$a, $b] = $this->admins($url, withLock: false);
        $id      = $a->seed();
        $second  = null;
        $a->beforeDispatch(static function () use ($b, $id, &$second): void {
            $second = $b->retrier()->retry($b->failedInfo(), $id);
        });

        self::assertSame(RetryStatus::Done, $a->retrier()->retry($a->failedInfo(), $id)->status);
        self::assertInstanceOf(RetryOutcome::class, $second);
        self::assertSame(RetryStatus::Done, $second->status);
        self::assertSame(2, $a->async->getMessageCount());
    }

    #[DataProvider('databases')]
    public function testARemovalBlockedByARowLockAnswersConflict001(string $url): void
    {
        [$a, $b] = $this->admins($url);
        $id      = $a->seed();
        $b->lockRow($id);

        $failure = $this->failure(static fn (): RetryOutcome => $a->retrier()->discard($a->failedInfo(), $id));
        $b->connection->rollBack();

        self::assertNotInstanceOf(ConcurrencyConflict::class, $failure, 'nothing was committed');
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
        $response = $this->answer($failure, 'remove');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame(1, $a->failed->getMessageCount(), 'the message is still parked');
        self::assertSame(RetryStatus::Done, $a->retrier()->discard($a->failedInfo(), $id)->status, 'the connection is still usable and the retry succeeds');
    }

    #[DataProvider('databases')]
    public function testARetryWhoseRejectIsBlockedAnswersConflict002(string $url): void
    {
        [$a, $b] = $this->admins($url);
        $id      = $a->seed();
        $b->lockRow($id);

        $failure = $this->failure(static fn (): RetryOutcome => $a->retrier()->retry($a->failedInfo(), $id));
        $b->connection->rollBack();

        self::assertInstanceOf(ConcurrencyConflict::class, $failure);
        self::assertTrue($failure->committed, 'the message was already sent back');
        $response = $this->answer($failure, 'retry');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'), 'CONFLICT_002 never invites a blind retry');
        self::assertSame(1, $a->async->getMessageCount(), 'sent back once');
        self::assertSame(1, $a->failed->getMessageCount(), 'still parked: the documented residual risk, read again before acting');
    }

    #[DataProvider('databases')]
    public function testAPurgeBlockedAfterItsFirstBatchIsAPartialCommit(string $url): void
    {
        [$a, $b] = $this->admins($url);
        foreach ([$a->connection, $b->connection] as $connection) {
            $connection->executeStatement('DROP TABLE IF EXISTS mmk_race_history');
        }
        $a->connection->executeStatement('CREATE TABLE mmk_race_history (id INT NOT NULL, PRIMARY KEY (id))');
        foreach (range(1, 5) as $row) {
            $a->connection->insert('mmk_race_history', ['id' => $row]);
        }

        $b->connection->beginTransaction();
        $b->connection->fetchOne('SELECT id FROM mmk_race_history WHERE id = 3 FOR UPDATE');
        $partial = $this->failure(static fn (): mixed => (new KeysetDeleter($a->connection))->delete('mmk_race_history', 'id', '', [], [], 2));
        $b->connection->rollBack();

        self::assertInstanceOf(ConcurrencyConflict::class, $partial, 'the first batch was committed');
        self::assertTrue($partial->committed);
        self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($partial));
        self::assertSame(3, (int) $a->connection->fetchOne('SELECT COUNT(*) FROM mmk_race_history'));

        $b->connection->beginTransaction();
        $b->connection->fetchOne('SELECT id FROM mmk_race_history WHERE id = 3 FOR UPDATE');
        $first = $this->failure(static fn (): mixed => (new KeysetDeleter($a->connection))->delete('mmk_race_history', 'id', '', [], [], 2));
        $b->connection->rollBack();

        self::assertNotInstanceOf(ConcurrencyConflict::class, $first, 'blocked on its first batch: nothing committed');
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($first));
        self::assertTrue(MessengerMonitorConflictListener::isConflict($first));
        self::assertSame(3, (int) $a->connection->fetchOne('SELECT COUNT(*) FROM mmk_race_history'), 'no lost or extra delete');

        self::assertSame(3, (new KeysetDeleter($a->connection))->delete('mmk_race_history', 'id', '', [], [], 2)->deleted, 'the connection is still usable');
        $a->connection->executeStatement('DROP TABLE mmk_race_history');
    }

    /**
     * @param callable(): mixed $action
     */
    private function failure(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $e) {
            return $e;
        }

        self::fail('A lock conflict was expected.');
    }

    private function answer(Throwable $failure, string $action): Response
    {
        $request = Request::create('/admin/messenger/transports/failed/messages/1/' . $action, 'POST');
        $request->attributes->set('_controller', TransportsController::class . '::' . $action);
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        (new MessengerMonitorConflictListener())->onKernelException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return array{RaceAdmin, RaceAdmin}
     */
    private function admins(string $url, bool $withLock = true): array
    {
        if ($url === '') {
            self::markTestSkipped('Database URL not set (NOWO_TEST_MYSQL_URL / NOWO_TEST_MARIADB_URL / NOWO_TEST_PGSQL_URL).');
        }

        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']))->parse($url);
        $a      = DriverManager::getConnection($params);
        $b      = DriverManager::getConnection($params);
        foreach ([$a, $b] as $connection) {
            $this->connections[] = $connection;
            $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        }

        $a->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $a->executeStatement('DROP TABLE IF EXISTS lock_keys');
        (new MessengerConnection(['table_name' => 'messenger_messages', 'queue_name' => 'failed', 'auto_setup' => false], $a))->setup();
        (new DoctrineDbalStore($a, gcProbability: 0.0))->createTable();

        return [new RaceAdmin($a, $withLock), new RaceAdmin($b, $withLock)];
    }
}
