<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Recording;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Component\Messenger\Envelope;
use Throwable;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Writes and deletes history rows with plain DBAL statements built from the entity's class metadata (1.1, rule 6).
 *
 * zenstruck's ORM storage does `persist()` + `flush()` on the manager that maps the history entity, after every
 * handled AND failed message (worker events). With the default mapping that manager is the host's default one, so a
 * failed handler's half-done, never-flushed changes were committed by the history write. This writer never touches a
 * unit of work: one INSERT / DELETE on the history entity's connection, inside a SAVEPOINT when a transaction is open
 * (the row then commits or rolls back with that transaction, and a failed insert never breaks it).
 *
 * Stateless (worker-safe): the manager, metadata and connection are resolved per call.
 */
final readonly class DbalHistoryWriter
{
    /**
     * @param class-string<ProcessedMessage> $entityClass history entity (zenstruck `storage.orm.entity_class`)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityClass,
    ) {
    }

    /**
     * Inserts the history row of a handled or failed message.
     */
    public function save(Envelope $envelope, Results $results, ?Throwable $exception = null): void
    {
        $metadata = $this->metadata();
        $entity   = new ($this->entityClass)($envelope, $results, $exception);

        $row   = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field) && $metadata->usesIdGenerator()) {
                continue;
            }

            $column         = $metadata->getColumnName($field);
            $row[$column]   = $metadata->getFieldValue($entity, $field);
            $types[$column] = $metadata->getTypeOfField($field) ?? 'string';
        }

        $connection = $this->connection();
        $this->inSavepoint($connection, static function () use ($connection, $metadata, $row, $types): void {
            $connection->insert($metadata->getTableName(), $row, $types);
        });
    }

    /**
     * Deletes one history row by id.
     */
    public function delete(mixed $id): void
    {
        $metadata   = $this->metadata();
        $connection = $this->connection();
        $this->inSavepoint($connection, static function () use ($connection, $metadata, $id): void {
            $connection->delete($metadata->getTableName(), [$metadata->getSingleIdentifierColumnName() => $id]);
        });
    }

    /**
     * Runs the statement in a SAVEPOINT when the connection is inside a transaction; on failure the savepoint is
     * rolled back and the ORIGINAL error is rethrown (a failing rollback never hides it).
     *
     * @param callable(): void $statement
     */
    private function inSavepoint(Connection $connection, callable $statement): void
    {
        if (!$connection->isTransactionActive()) {
            $statement();

            return;
        }

        $savepoint = 'nowo_mmk_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $statement();
        } catch (Throwable $e) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                // The original error matters more than a failing rollback.
            }

            throw $e;
        }

        $connection->releaseSavepoint($savepoint);
    }

    /**
     * @return ClassMetadata<ProcessedMessage>
     */
    private function metadata(): ClassMetadata
    {
        return $this->manager()->getClassMetadata($this->entityClass);
    }

    private function connection(): Connection
    {
        return $this->manager()->getConnection();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass($this->entityClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager handles the messenger history entity "%s".', $this->entityClass));
        }

        return $manager;
    }
}
