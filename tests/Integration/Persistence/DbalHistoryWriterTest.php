<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use LogicException;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\Recording\DbalHistoryWriter;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Throwable;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage as BaseProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

use function dirname;
use function getenv;

use const PHP_VERSION_ID;

/**
 * {@see DbalHistoryWriter} on a real ORM + DBAL connection: SQLite always, MySQL / PostgreSQL when
 * `NOWO_TEST_MYSQL_URL` / `NOWO_TEST_PGSQL_URL` are set (CI database job).
 */
final class DbalHistoryWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function platforms(): iterable
    {
        yield 'sqlite' => ['pdo-sqlite:///:memory:'];
        yield 'mysql' => [(string) getenv('NOWO_TEST_MYSQL_URL')];
        yield 'postgresql' => [(string) getenv('NOWO_TEST_PGSQL_URL')];
    }

    #[DataProvider('platforms')]
    public function testInsertsWithoutTouchingTheUnitOfWork(string $url): void
    {
        $em      = $this->entityManager($url);
        $pending = new ProcessedMessage($this->envelope(), new Results([]));
        $em->persist($pending);

        $this->writer($em)->save($this->envelope(), new Results([['handler' => 'App\\Handler', 'data' => ['ok' => true]]]), new RuntimeException('Refused.'));

        $row = $em->getConnection()->fetchAssociative('SELECT * FROM messenger_processed_messages');
        self::assertIsArray($row);
        self::assertSame(DemoMessage::class, $row['message_type']);
        self::assertSame('async', $row['transport']);
        self::assertSame(RuntimeException::class, $row['failure_type']);
        self::assertSame('Refused.', $row['failure_message']);
        self::assertStringContainsString('App\\\\Handler', (string) $row['results']);
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'), 'the pending entity was not flushed');
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($pending));

        $id = $em->getConnection()->fetchOne('SELECT id FROM messenger_processed_messages');
        $this->writer($em)->delete($id);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));
    }

    #[DataProvider('platforms')]
    public function testInsideAHostTransactionTheRowFollowsThatTransaction(string $url): void
    {
        $em         = $this->entityManager($url);
        $connection = $em->getConnection();

        $connection->beginTransaction();
        $this->writer($em)->save($this->envelope(), new Results([]));
        self::assertTrue($connection->isTransactionActive());
        $connection->rollBack();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));

        $connection->beginTransaction();
        $this->writer($em)->save($this->envelope(), new Results([]));
        $connection->commit();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));
    }

    #[DataProvider('platforms')]
    public function testAFailedInsertRethrowsTheOriginalErrorAndKeepsTheHostTransactionUsable(string $url): void
    {
        $em         = $this->entityManager($url);
        $connection = $em->getConnection();
        // DDL outside the transaction (MySQL commits implicitly on DDL).
        $connection->executeStatement('DROP TABLE messenger_processed_messages');
        $connection->beginTransaction();
        $connection->insert('host_marker', ['label' => 'before']);

        try {
            $this->writer($em)->save($this->envelope(), new Results([]));
            self::fail('The insert must fail.');
        } catch (Throwable $e) {
            self::assertStringContainsString('messenger_processed_messages', $e->getMessage());
        }

        $connection->insert('host_marker', ['label' => 'after']);
        $connection->commit();
        self::assertSame(['before', 'after'], $connection->fetchFirstColumn('SELECT label FROM host_marker ORDER BY label DESC'));
    }

    public function testFailsClearlyWithoutAManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine ORM entity manager handles');
        (new DbalHistoryWriter($registry, ProcessedMessage::class))->delete(1);
    }

    public function testAFailingSavepointRollbackNeverHidesTheOriginalError(): void
    {
        $real       = $this->entityManager('pdo-sqlite:///:memory:');
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('createSavepoint');
        $connection->method('delete')->willThrowException(new RuntimeException('original'));
        $connection->method('rollbackSavepoint')->willThrowException(new RuntimeException('rollback failed'));
        $connection->expects(self::never())->method('releaseSavepoint');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($real->getClassMetadata(ProcessedMessage::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('original');
        $this->writer($em)->delete(1);
    }

    private function writer(EntityManagerInterface $em): DbalHistoryWriter
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new DbalHistoryWriter($registry, ProcessedMessage::class);
    }

    private function envelope(): Envelope
    {
        return new Envelope(new DemoMessage(), [(new MonitorStamp())->markReceived('async')->markFinished()]);
    }

    private function entityManager(string $url): EntityManagerInterface
    {
        if ($url === '') {
            self::markTestSkipped('Database URL not set (NOWO_TEST_MYSQL_URL / NOWO_TEST_PGSQL_URL).');
        }

        $base = dirname((string) (new ReflectionClass(BaseProcessedMessage::class))->getFileName(), 4);
        $root = dirname(__DIR__, 3);

        $chain = new MappingDriverChain();
        $chain->addDriver(new AttributeDriver([$root . '/src/Entity']), 'Nowo\\MessengerMonitorKitBundle\\Entity');
        $chain->addDriver(new SimplifiedXmlDriver([$base . '/config/doctrine/mapping' => 'Zenstruck\\Messenger\\Monitor\\History\\Model']), 'Zenstruck\\Messenger\\Monitor\\History\\Model');

        $config = ORMSetup::createConfiguration(true);
        $config->setMetadataDriverImpl($chain);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params = (new DsnParser(['sqlite' => 'pdo_sqlite', 'pdo-sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']))->parse($url);
        $em     = new EntityManager(DriverManager::getConnection($params, $config), $config);

        $connection = $em->getConnection();
        foreach (['messenger_processed_messages', 'host_marker'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $connection->executeStatement('CREATE TABLE host_marker (label VARCHAR(16) NOT NULL)');
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(ProcessedMessage::class)]);

        return $em;
    }
}
