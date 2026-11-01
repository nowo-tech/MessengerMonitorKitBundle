<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\History;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MessengerMonitorKitBundle\Doctrine\KeysetDeleter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function implode;
use function sprintf;

/**
 * Deletes Messenger history in keyset batches (manual purge, automatic purge, CLI) instead of the single
 * `DELETE … WHERE finished_at <= ?` of zenstruck's storage: a month of history on a busy instance is millions of rows.
 * Same filters: `finished_at <= cutoff`, and only successful rows when failed ones are kept. Table and columns come
 * from the Doctrine metadata of the configured history entity (prefix included). Stateless.
 */
final readonly class HistoryPurger
{
    /**
     * @param ManagerRegistry $registry Doctrine registry
     * @param class-string $entityClass history entity (zenstruck `storage.orm.entity_class`)
     * @param int $batchSize rows per delete statement (`purge.batch_size`)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $entityClass,
        private ClockInterface $clock,
        private int $batchSize = 1000,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Purges the rows finished before the period cutoff.
     *
     * @param bool $keepFailed keep rows with a failure
     * @param int|null $maxBatches stop after this many batches (HTTP cap), null = until done
     */
    public function purge(PurgePeriod $olderThan, bool $keepFailed, ?int $maxBatches = null): PurgeResult
    {
        $manager = $this->registry->getManagerForClass($this->entityClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager handles the messenger history entity "%s".', $this->entityClass));
        }

        $metadata = $manager->getClassMetadata($this->entityClass);
        $where    = [];
        $params   = [];
        $types    = [];
        $cutoff   = $olderThan->cutoff($this->clock->now());
        if ($cutoff instanceof DateTimeImmutable) {
            $where[]  = $metadata->getColumnName('finishedAt') . ' <= ?';
            $params[] = $cutoff;
            $types[]  = Types::DATETIME_IMMUTABLE;
        }
        if ($keepFailed) {
            $where[] = $metadata->getColumnName('failureType') . ' IS NULL';
        }

        $result = (new KeysetDeleter($manager->getConnection()))->delete(
            $metadata->getTableName(),
            $metadata->getSingleIdentifierColumnName(),
            implode(' AND ', $where),
            $params,
            $types,
            $this->batchSize,
            $maxBatches,
        );

        $this->logger?->info('Messenger monitor history purged.', [
            'bundle'      => 'messenger_monitor_kit',
            'older_than'  => $olderThan->value,
            'keep_failed' => $keepFailed,
            'deleted'     => $result->deleted,
            'complete'    => $result->complete,
        ]);

        return $result;
    }
}
