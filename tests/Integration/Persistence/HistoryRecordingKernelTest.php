<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\Recording\OwnHistoryManagerSubscriber;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\Host\HostNote;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\MonitorTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

use function dirname;
use function memory_get_usage;

/**
 * Rule 6 (1.1): recording the history after a handled or failed message never flushes the host's entity manager,
 * and `doctrine.entity_manager` keeps the history entity on a dedicated manager (kept-alive worker, no resetter).
 */
final class HistoryRecordingKernelTest extends KernelTestCase
{
    public function testFailedMessageDoesNotFlushTheHostsPendingChanges(): void
    {
        $kernel  = $this->bootKernel(['web_ui' => ['enabled' => false]], extensions: ['doctrine' => ['orm' => ['mappings' => $this->hostMapping()]]]);
        $default = $this->manager($kernel, 'default');
        self::assertSame($default, $this->registry($kernel)->getManagerForClass(ProcessedMessage::class));
        $this->createSchema($default);

        // A host failure listener (after Doctrine's worker clear at priority 0, before the history at -100)
        // persists a note it flushes later itself: the history write must not commit it.
        $note = new HostNote('pending change of a host listener');
        $this->dispatcher($kernel)->addListener(WorkerMessageFailedEvent::class, static function () use ($default, $note): void {
            $default->persist($note);
        }, -50);
        $this->dispatcher($kernel)->dispatch(new WorkerMessageFailedEvent($this->envelope(), 'async', new RuntimeException('Handler failed.')));
        $connection = $default->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_note'), 'the host change was not flushed');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note));

        $this->dispatcher($kernel)->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));
        self::assertSame('Handler failed.', $connection->fetchOne('SELECT failure_message FROM messenger_processed_messages WHERE failure_type IS NOT NULL'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_note'));
    }

    public function testDedicatedManagerWithAutoMappingOnDefault(): void
    {
        $kernel   = $this->bootDedicated(autoMapping: true);
        $registry = $this->registry($kernel);
        $default  = $this->manager($kernel, 'default');
        $monitor  = $this->manager($kernel, 'monitor');

        self::assertTrue($default->getMetadataFactory()->isTransient(ProcessedMessage::class), 'not mapped on the auto-mapped default manager');
        self::assertSame($monitor, $registry->getManagerForClass(ProcessedMessage::class));
        self::assertSame('default', $registry->getDefaultManagerName());

        $note = new HostNote('pending');
        $this->dispatcher($kernel)->addListener(WorkerMessageHandledEvent::class, static function () use ($default, $note): void {
            $default->persist($note);
        }, -50);
        $this->dispatcher($kernel)->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        self::assertSame(1, (int) $monitor->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note));

        $storage = $this->container($kernel)->get('zenstruck_messenger_monitor.history.storage');
        self::assertInstanceOf(Storage::class, $storage);
        $this->dispatcher($kernel)->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        $storage->delete(2);
        self::assertSame(1, (int) $monitor->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'), 'delete by DBAL');

        $purger = $this->container($kernel)->get(HistoryPurger::class);
        self::assertInstanceOf(HistoryPurger::class, $purger);
        self::assertSame(1, $purger->purge(PurgePeriod::All, false)->deleted);
    }

    public function testManagersWithoutDefaultEntityManagerKeepTheHostDefault(): void
    {
        $kernel   = $this->bootDedicated(autoMapping: false, defaultName: false);
        $registry = $this->registry($kernel);

        self::assertSame('default', $registry->getDefaultManagerName());
        self::assertSame($this->manager($kernel, 'monitor'), $registry->getManagerForClass(ProcessedMessage::class));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function hostDefaultLayouts(): iterable
    {
        yield 'flex shorthand' => [false];
        yield 'declared managers' => [true];
    }

    #[DataProvider('hostDefaultLayouts')]
    public function testOptionNamingTheHostDefaultNeverClearsOrResetsIt(bool $declared): void
    {
        $orm = $declared
            ? ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default', 'mappings' => self::hostMapping()], 'monitor' => ['connection' => 'default']]]
            : ['mappings' => self::hostMapping()];
        $kernel  = $this->bootKernel(['web_ui' => ['enabled' => false], 'doctrine' => ['entity_manager' => 'default']], extensions: ['doctrine' => ['orm' => $orm]]);
        $default = $this->manager($kernel, 'default');
        $this->createSchema($default);
        self::assertSame($default, $this->registry($kernel)->getManagerForClass(ProcessedMessage::class), 'LH-MMK-L1: mapped on the host default');
        self::assertFalse($this->container($kernel)->has(OwnHistoryManagerSubscriber::class), 'LH-MMK-M1');

        // After DoctrineBundle's worker clear (0) and the history write (-100), before the own-manager clear (-1024).
        $note = new HostNote('pending host change');
        $this->dispatcher($kernel)->addListener(WorkerMessageHandledEvent::class, static function () use ($default, $note): void {
            $default->persist($note);
        }, -500);
        $this->dispatcher($kernel)->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note), 'the host default is not cleared by the bundle');
        self::assertSame(1, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));
    }

    public function testUnknownEntityManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_messenger_monitor_kit.doctrine.entity_manager is "missing"');

        $this->bootKernel(['web_ui' => ['enabled' => false], 'doctrine' => ['entity_manager' => 'missing']]);
    }

    public function testEntityManagerNeedsTheBundleEntity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine.entity_manager places the bundle history entity');

        $this->bootKernel(['web_ui' => ['enabled' => false], 'history' => ['bundle_entity' => false], 'doctrine' => ['entity_manager' => 'monitor']]);
    }

    public function testKeptAliveWorkerClearsTheOwnManagerAndKeepsMemoryFlat(): void
    {
        $kernel  = $this->bootDedicated(autoMapping: true);
        $monitor = $this->manager($kernel, 'monitor');
        self::assertTrue($this->container($kernel)->has(OwnHistoryManagerSubscriber::class));
        $storage = $this->container($kernel)->get('zenstruck_messenger_monitor.history.storage');
        self::assertInstanceOf(Storage::class, $storage);

        $dispatcher = $this->dispatcher($kernel);
        $baseline   = 0;
        for ($i = 0; $i < 300; ++$i) {
            $dispatcher->dispatch(new WorkerMessageHandledEvent($this->envelope(), 'async'));
            self::assertNotNull($storage->find($i + 1), 'the UI read loads the row into the bundle manager');
            $dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope(new DemoMessage()), 'async'));
            self::assertSame(0, $monitor->getUnitOfWork()->size(), 'cleared at the end of the message');
            if ($i === 50) {
                $baseline = memory_get_usage();
            }
        }

        self::assertSame(300, (int) $monitor->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_processed_messages'));
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
    }

    private function bootDedicated(bool $autoMapping, bool $defaultName = true): MonitorTestKernel
    {
        $orm = ['entity_managers' => [
            'default' => ['connection' => 'default', 'auto_mapping' => $autoMapping, 'mappings' => $this->hostMapping()],
            'monitor' => ['connection' => 'default'],
        ]];
        if ($defaultName) {
            $orm = ['default_entity_manager' => 'default'] + $orm;
        }

        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false], 'doctrine' => ['entity_manager' => 'monitor']], extensions: ['doctrine' => ['orm' => $orm]]);
        $this->createSchema($this->manager($kernel, 'monitor'));

        return $kernel;
    }

    private function envelope(): Envelope
    {
        return new Envelope(new DemoMessage(), [(new MonitorStamp())->markReceived('async')]);
    }

    private function createSchema(EntityManagerInterface $manager): void
    {
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
    }

    private function registry(MonitorTestKernel $kernel): ManagerRegistry
    {
        $registry = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        return $registry;
    }

    private function manager(MonitorTestKernel $kernel, string $name): EntityManagerInterface
    {
        $manager = $this->registry($kernel)->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function dispatcher(MonitorTestKernel $kernel): EventDispatcherInterface
    {
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hostMapping(): array
    {
        return ['Host' => [
            'type'      => 'attribute',
            'dir'       => dirname(__DIR__, 2) . '/Fixtures/Host',
            'prefix'    => 'Nowo\\MessengerMonitorKitBundle\\Tests\\Fixtures\\Host',
            'is_bundle' => false,
        ]];
    }
}
