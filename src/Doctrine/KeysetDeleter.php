<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nowo\MessengerMonitorKitBundle\Concurrency\PartialCommit;
use Nowo\MessengerMonitorKitBundle\History\PurgeResult;
use Throwable;

use function array_fill;
use function array_merge;
use function count;
use function end;
use function implode;
use function preg_match;
use function sprintf;

/**
 * Deletes rows of one table in primary-key keyset batches: `SELECT id … WHERE id > :last ORDER BY id LIMIT n`, then
 * `DELETE … WHERE id IN (…)`. Each statement touches at most one batch, so a large purge never holds locks or undo for
 * the whole set while workers keep inserting, and retained rows are never rescanned.
 *
 * Identifiers come from Doctrine metadata (never from user input) and are validated as plain SQL identifiers; values
 * are bound parameters.
 *
 * Since 1.3.0 (REQ-RUNTIME-003 rule 6): a lock conflict after the first deleted batch is thrown as a committed
 * conflict ({@see PartialCommit}, `CONFLICT_002`); before it, the original failure is thrown (`CONFLICT_001`).
 */
final readonly class KeysetDeleter
{
    /**
     * @param Connection $connection DBAL connection of the table
     */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Deletes the rows matching `$where` (SQL with `?` placeholders bound to `$params` / `$types`).
     *
     * @param list<mixed> $params
     * @param list<string> $types DBAL type names of `$params`
     * @param int $batchSize rows per batch
     * @param int|null $maxBatches stop after this many batches (null = until done)
     */
    public function delete(string $table, string $idColumn, string $where, array $params, array $types, int $batchSize, ?int $maxBatches = null): PurgeResult
    {
        $this->assertIdentifier($table);
        $this->assertIdentifier($idColumn);
        $condition = $where === '' ? '' : ' AND (' . $where . ')';
        $deleted   = 0;
        $batches   = 0;
        $last      = null;

        while (true) {
            try {
                [$ids, $count] = $this->batch($table, $idColumn, $condition, $params, $types, $batchSize, $last);
            } catch (Throwable $e) {
                throw $batches > 0 ? PartialCommit::conflict($e) : $e;
            }
            if ($ids === []) {
                return new PurgeResult($deleted, true);
            }

            $deleted += $count;
            $last = end($ids);
            ++$batches;

            if (count($ids) < $batchSize) {
                return new PurgeResult($deleted, true);
            }
            if ($maxBatches !== null && $batches >= $maxBatches) {
                return new PurgeResult($deleted, false);
            }
        }
    }

    /**
     * Selects the next batch of ids after `$last` and deletes it.
     *
     * @param list<mixed> $params
     * @param list<string> $types
     *
     * @return array{list<mixed>, int} the batch ids and the deleted row count
     */
    private function batch(string $table, string $idColumn, string $condition, array $params, array $types, int $batchSize, mixed $last): array
    {
        $select = sprintf('SELECT %2$s FROM %1$s WHERE %3$s%4$s ORDER BY %2$s ASC', $table, $idColumn, $last === null ? '1 = 1' : $idColumn . ' > ?', $condition);
        $ids    = $this->connection->fetchFirstColumn(
            $this->connection->getDatabasePlatform()->modifyLimitQuery($select, $batchSize),
            $last === null ? $params : array_merge([$last], $params),
            $last === null ? $types : $this->shiftTypes($types),
        );
        if ($ids === []) {
            return [[], 0];
        }

        return [$ids, (int) $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s IN (%s)', $table, $idColumn, implode(', ', array_fill(0, count($ids), '?'))),
            $ids,
        )];
    }

    /**
     * Types of `$params` once the keyset value is prepended (index 0 untyped).
     *
     * @param list<string> $types
     *
     * @return array<int<1, max>, string>
     */
    private function shiftTypes(array $types): array
    {
        $shifted = [];
        foreach ($types as $index => $type) {
            $shifted[$index + 1] = $type;
        }

        return $shifted;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsupported SQL identifier "%s" for a keyset delete.', $identifier));
        }
    }
}
