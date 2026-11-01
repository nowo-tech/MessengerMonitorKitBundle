<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\MessengerMonitorKitBundle\Doctrine\TablePrefixListener;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    #[Test]
    public function itPrefixesOnlyBundleEntities(): void
    {
        $bundle = $this->metadata(ProcessedMessage::class);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($bundle, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('app_messenger_processed_messages', $bundle->getTableName());
        self::assertArrayHasKey('app_idx_mpm_finished_at', $bundle->table['indexes'] ?? []);
        self::assertArrayHasKey(0, $bundle->table['uniqueConstraints'] ?? []);

        $host = $this->metadata(stdClass::class);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($host, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('host_table', $host->getTableName());

        $bare = new ClassMetadata(ProcessedMessage::class);
        $bare->setPrimaryTable(['name' => ProcessedMessage::TABLE]);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($bare, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('app_messenger_processed_messages', $bare->getTableName());

        $unprefixed = $this->metadata(ProcessedMessage::class);
        (new TablePrefixListener())->loadClassMetadata(new LoadClassMetadataEventArgs($unprefixed, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('messenger_processed_messages', $unprefixed->getTableName());
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name'              => $class === stdClass::class ? 'host_table' : ProcessedMessage::TABLE,
            'indexes'           => ['idx_mpm_finished_at' => ['columns' => ['finished_at']]],
            'uniqueConstraints' => [0 => ['columns' => ['run_id']]],
        ]);

        return $metadata;
    }
}
