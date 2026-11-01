<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage as BaseProcessedMessage;

/**
 * Messenger history row written by the workers after each handled / failed message (zenstruck ORM storage).
 *
 * Read-only for Doctrine: rows are inserted by the storage and deleted by the batched purge. Table
 * `messenger_processed_messages` (+ `doctrine.table_prefix`), indexes on `finished_at` (period filters, purge) and
 * `run_id` (attempts of one message).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: self::TABLE)]
#[ORM\Index(name: 'idx_mpm_finished_at', columns: ['finished_at'])]
#[ORM\Index(name: 'idx_mpm_run_id', columns: ['run_id'])]
class ProcessedMessage extends BaseProcessedMessage
{
    /** Unprefixed table name. */
    public const TABLE = 'messenger_processed_messages';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Auto-increment id (null before the row is flushed).
     */
    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * @internal Doctrine assigns the id through reflection; tests and fixtures may set it
     */
    public function setId(int $id): self
    {
        $this->id = $id;

        return $this;
    }
}
